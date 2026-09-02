package eos

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

// pageFixture builds one filter response with the given number of sessions
// and the given totalCount. Sessions get incrementing IPs so the dedup by
// address doesn't fold them together.
func pageFixture(count, totalCount, startOffset int) map[string]any {
	sessions := make([]map[string]any, 0, count)
	for i := 0; i < count; i++ {
		idx := startOffset + i + 1
		sessions = append(sessions, map[string]any{
			"totalPlayers":      float64(0),
			"openPublicPlayers": float64(70),
			"attributes": map[string]any{
				"ADDRESS_s":  ipFromIndex(idx),
				"GAMEPORT_l": float64(7779),
			},
		})
	}
	return map[string]any{
		"sessions": sessions,
		"pagination": map[string]any{
			"count":      count,
			"totalCount": totalCount,
		},
	}
}

func ipFromIndex(i int) string {
	// Cheap unique IPs — the sweep dedups by ip:port, so distinct IPs mean
	// distinct sessions.
	return "10.0." + itoa(i/256) + "." + itoa(i%256)
}

// fakeEpic serves /auth/v1/oauth/token and /matchmaking/v1/{d}/filter with
// pre-baked responses, and counts filter hits so a test can assert how many
// pages the sweep asked for.
type fakeEpic struct {
	srv         *httptest.Server
	filterCount atomic.Int32
	pages       []map[string]any
}

func newFakeEpic(pages []map[string]any) *fakeEpic {
	fe := &fakeEpic{pages: pages}
	mux := http.NewServeMux()
	mux.HandleFunc("/auth/v1/oauth/token", func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewEncoder(w).Encode(map[string]any{
			"access_token": "fake-token",
			"token_type":   "bearer",
			"expires_in":   3600,
		})
	})
	mux.HandleFunc("/matchmaking/v1/", func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasSuffix(r.URL.Path, "/filter") {
			http.NotFound(w, r)
			return
		}
		i := int(fe.filterCount.Add(1)) - 1
		if i >= len(fe.pages) {
			// Ran past the fixtures — return an empty page so the sweep ends
			// cleanly rather than a 404 masking a bug.
			_ = json.NewEncoder(w).Encode(map[string]any{"sessions": []any{}})
			return
		}
		_ = json.NewEncoder(w).Encode(fe.pages[i])
	})
	fe.srv = httptest.NewServer(mux)
	return fe
}

func (fe *fakeEpic) close() { fe.srv.Close() }

func newTestClient(baseURL string, pageSize int) *Client {
	return New(baseURL, 5*time.Second, 1, 0, pageSize)
}

func testDeployment() Deployment {
	return Deployment{Slug: "test", DeploymentID: "d", ClientID: "c", ClientSecret: "s"}
}

func TestSweep_stopsOnShortPage(t *testing.T) {
	fe := newFakeEpic([]map[string]any{
		pageFixture(2, 0, 0),
		pageFixture(2, 0, 2),
		pageFixture(1, 0, 4), // short
	})
	defer fe.close()

	client := newTestClient(fe.srv.URL, 2)
	res, err := Sweep(context.Background(), client, testDeployment(), 0)
	if err != nil {
		t.Fatalf("sweep: %v", err)
	}
	if res.Pages != 3 {
		t.Errorf("pages = %d, want 3", res.Pages)
	}
	if res.Found != 5 || res.Distinct != 5 {
		t.Errorf("found=%d distinct=%d, want 5/5", res.Found, res.Distinct)
	}
}

func TestSweep_stopsOnTotalCountBeforeShortPage(t *testing.T) {
	// totalCount = 4, so after the second full page (offset=4) the sweep
	// ends without asking for the (non-existent) third page.
	fe := newFakeEpic([]map[string]any{
		pageFixture(2, 4, 0),
		pageFixture(2, 4, 2),
	})
	defer fe.close()

	client := newTestClient(fe.srv.URL, 2)
	res, err := Sweep(context.Background(), client, testDeployment(), 0)
	if err != nil {
		t.Fatalf("sweep: %v", err)
	}
	if res.Pages != 2 {
		t.Errorf("pages = %d, want 2 (totalCount stop)", res.Pages)
	}
	if fe.filterCount.Load() != 2 {
		t.Errorf("filter calls = %d, want 2", fe.filterCount.Load())
	}
}

func TestSweep_dedupesRepeatedAddresses(t *testing.T) {
	// Same session twice in one page.
	duplicate := map[string]any{
		"totalPlayers":      float64(5),
		"openPublicPlayers": float64(65),
		"attributes": map[string]any{
			"ADDRESS_s":  "1.2.3.4",
			"GAMEPORT_l": float64(7779),
		},
	}
	fe := newFakeEpic([]map[string]any{
		{
			"sessions":   []map[string]any{duplicate, duplicate},
			"pagination": map[string]any{"totalCount": 2},
		},
	})
	defer fe.close()

	client := newTestClient(fe.srv.URL, 10)
	res, err := Sweep(context.Background(), client, testDeployment(), 0)
	if err != nil {
		t.Fatalf("sweep: %v", err)
	}
	if res.Found != 2 || res.Distinct != 1 {
		t.Errorf("found=%d distinct=%d, want 2/1", res.Found, res.Distinct)
	}
}

func TestSweep_capsAtMaxPages(t *testing.T) {
	fe := newFakeEpic([]map[string]any{
		pageFixture(2, 100, 0),
		pageFixture(2, 100, 2),
		pageFixture(2, 100, 4), // would be fetched if maxPages > 2
	})
	defer fe.close()

	client := newTestClient(fe.srv.URL, 2)
	res, err := Sweep(context.Background(), client, testDeployment(), 2)
	if err != nil {
		t.Fatalf("sweep: %v", err)
	}
	if res.Pages != 2 {
		t.Errorf("pages = %d, want 2 (max cap)", res.Pages)
	}
	if fe.filterCount.Load() != 2 {
		t.Errorf("filter calls = %d, want 2 (max cap)", fe.filterCount.Load())
	}
}

func TestResolveFromEnv_missingCredsReturnsError(t *testing.T) {
	// No env vars set for this slug — the resolver has to say which ones to fill.
	t.Setenv("EOS_UNCONFIGURED_GAME_DEPLOYMENT_ID", "")
	t.Setenv("EOS_UNCONFIGURED_GAME_CLIENT_ID", "")
	t.Setenv("EOS_UNCONFIGURED_GAME_CLIENT_SECRET", "")

	_, err := ResolveFromEnv("unconfigured-game")
	if err == nil {
		t.Fatal("expected error for missing credentials")
	}
	if !strings.Contains(err.Error(), "EOS_UNCONFIGURED_GAME_DEPLOYMENT_ID") {
		t.Errorf("error should name the missing env var, got: %v", err)
	}
}

func TestResolveFromEnv_readsSlugAsEnvKey(t *testing.T) {
	t.Setenv("EOS_TEST_GAME_DEPLOYMENT_ID", "d")
	t.Setenv("EOS_TEST_GAME_CLIENT_ID", "c")
	t.Setenv("EOS_TEST_GAME_CLIENT_SECRET", "s")

	dep, err := ResolveFromEnv("test-game")
	if err != nil {
		t.Fatalf("resolve: %v", err)
	}
	if dep.DeploymentID != "d" || dep.ClientID != "c" || dep.ClientSecret != "s" {
		t.Errorf("resolved wrong triple: %+v", dep)
	}
	if dep.Slug != "test-game" {
		t.Errorf("slug not carried through: %q", dep.Slug)
	}
}
