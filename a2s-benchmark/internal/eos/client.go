package eos

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"
)

// Client talks to Epic's `api.epicgames.dev` — one instance for the whole
// process, safe for concurrent use.
//
// Two endpoints and one credential triple per game:
//
//   POST /auth/v1/oauth/token       ← client_credentials, deployment_id
//   POST /matchmaking/v1/{d}/filter ← bearer, {criteria, maxResults, pagination}
//
// Tokens are cached per deployment for their declared TTL minus a minute of
// headroom, so a walk of forty pages is one token fetch and forty filters.
type Client struct {
	baseURL  string
	http     *http.Client
	attempts int
	pauseMs  int
	pageSize int

	mu     sync.Mutex
	tokens map[string]cachedToken // key = deployment id
}

type cachedToken struct {
	value     string
	expiresAt time.Time
}

// tokenHeadroom keeps a token from being used in the last minute of its
// declared TTL: a request that starts on a token about to expire routinely
// comes back 401 from Epic's edge before the header refresh catches up. One
// minute of margin is cheap and eliminates that whole failure mode.
const tokenHeadroom = 60 * time.Second

// New constructs a Client. timeout applies to each HTTP call individually;
// attempts is the whole-call retry count (transport-level plus 5xx).
// pauseMs is politeness between paginated calls, and pageSize is what goes
// into every `maxResults` / `pagination.count` field.
func New(baseURL string, timeout time.Duration, attempts, pauseMs, pageSize int) *Client {
	return &Client{
		baseURL: strings.TrimRight(baseURL, "/"),
		http: &http.Client{
			Timeout: timeout,
		},
		attempts: attempts,
		pauseMs:  pauseMs,
		pageSize: pageSize,
		tokens:   make(map[string]cachedToken),
	}
}

// PageSize returns the value each filter request is sent with — same number
// the sweep uses to decide whether a response was a short page.
func (c *Client) PageSize() int { return c.pageSize }

// PauseMs returns the delay to observe between pages of a walk.
func (c *Client) PauseMs() int { return c.pauseMs }

// FilterResponse is one page of a matchmaking result, exactly what the sweep
// needs and no less: the raw session dicts (session normalisation is another
// file's job) and the pagination envelope that decides when to stop.
type FilterResponse struct {
	Sessions   []map[string]any
	TotalCount int
}

// Filter fetches one page of a deployment's session list at the given offset.
// Public so a dry-run mode can pull a single page without setting up a
// pagination loop.
func (c *Client) Filter(ctx context.Context, dep Deployment, offset int) (FilterResponse, error) {
	body, err := json.Marshal(map[string]any{
		"criteria":   []any{},
		"maxResults": c.pageSize,
		"pagination": map[string]any{
			"count":  c.pageSize,
			"offset": offset,
		},
	})
	if err != nil {
		return FilterResponse{}, fmt.Errorf("marshal filter body: %w", err)
	}

	endpoint := c.baseURL + "/matchmaking/v1/" + url.PathEscape(dep.DeploymentID) + "/filter"

	raw, err := c.postWithToken(ctx, endpoint, "application/json", body, dep)
	if err != nil {
		return FilterResponse{}, err
	}

	return parseFilter(raw)
}

// postWithToken sends a request under a valid bearer token, refreshing once
// on a 401 in case the cached token was rotated out by Epic's front. A second
// 401 is not swept away — it is a real credential problem.
func (c *Client) postWithToken(ctx context.Context, endpoint, contentType string, body []byte, dep Deployment) ([]byte, error) {
	token, err := c.token(ctx, dep)
	if err != nil {
		return nil, err
	}

	raw, status, err := c.doPost(ctx, endpoint, contentType, body, token)
	if err != nil {
		return nil, err
	}
	if status == http.StatusUnauthorized {
		// Force a refresh and try once more. Same reasoning as the PHP
		// side: the token TTL is a lower bound, not a promise.
		c.mu.Lock()
		delete(c.tokens, dep.DeploymentID)
		c.mu.Unlock()

		token, err = c.token(ctx, dep)
		if err != nil {
			return nil, err
		}

		raw, status, err = c.doPost(ctx, endpoint, contentType, body, token)
		if err != nil {
			return nil, err
		}
	}
	if status/100 != 2 {
		return nil, fmt.Errorf("EOS %s returned HTTP %d: %s", endpoint, status, redact(dep, string(raw)))
	}
	return raw, nil
}

// doPost is one attempt (with retries on transport error and 5xx) at posting
// a body to Epic. Status is returned separately from err so the caller can
// distinguish "did not connect" from "server said no" — the first is worth
// retrying at the transport level, the second is a decision the caller
// makes (a 401 gets a token refresh, a 400 gets propagated).
func (c *Client) doPost(ctx context.Context, endpoint, contentType string, body []byte, bearer string) ([]byte, int, error) {
	var lastErr error
	for attempt := 1; attempt <= c.attempts; attempt++ {
		req, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, bytes.NewReader(body))
		if err != nil {
			return nil, 0, fmt.Errorf("build request: %w", err)
		}
		req.Header.Set("Accept", "application/json")
		req.Header.Set("Content-Type", contentType)
		if bearer != "" {
			req.Header.Set("Authorization", "Bearer "+bearer)
		}

		resp, err := c.http.Do(req)
		if err != nil {
			lastErr = err
			if attempt < c.attempts {
				time.Sleep(time.Duration(attempt) * time.Second)
				continue
			}
			return nil, 0, fmt.Errorf("HTTP %s: %w", endpoint, err)
		}

		raw, readErr := io.ReadAll(resp.Body)
		_ = resp.Body.Close()
		if readErr != nil {
			lastErr = readErr
			if attempt < c.attempts {
				time.Sleep(time.Duration(attempt) * time.Second)
				continue
			}
			return nil, resp.StatusCode, fmt.Errorf("read %s: %w", endpoint, readErr)
		}

		// 5xx is worth retrying — Epic's front returns them under load, and
		// the same request usually succeeds a second later. 4xx is a decision
		// (401 → token refresh, others → error out); return it.
		if resp.StatusCode/100 == 5 && attempt < c.attempts {
			time.Sleep(time.Duration(attempt) * time.Second)
			continue
		}

		return raw, resp.StatusCode, nil
	}
	return nil, 0, lastErr
}

// token returns a valid bearer for dep, minting a fresh one when the cache
// is empty or within tokenHeadroom of the declared expiry.
func (c *Client) token(ctx context.Context, dep Deployment) (string, error) {
	c.mu.Lock()
	if cached, ok := c.tokens[dep.DeploymentID]; ok && time.Until(cached.expiresAt) > tokenHeadroom {
		c.mu.Unlock()
		return cached.value, nil
	}
	c.mu.Unlock()

	form := url.Values{
		"grant_type":    []string{"client_credentials"},
		"deployment_id": []string{dep.DeploymentID},
	}

	endpoint := c.baseURL + "/auth/v1/oauth/token"

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, strings.NewReader(form.Encode()))
	if err != nil {
		return "", fmt.Errorf("build token request: %w", err)
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.SetBasicAuth(dep.ClientID, dep.ClientSecret)

	var (
		raw    []byte
		status int
	)
	for attempt := 1; attempt <= c.attempts; attempt++ {
		resp, err := c.http.Do(req.Clone(ctx))
		if err != nil {
			if attempt < c.attempts {
				time.Sleep(time.Duration(attempt) * time.Second)
				continue
			}
			return "", fmt.Errorf("token HTTP: %w", err)
		}
		raw, err = io.ReadAll(resp.Body)
		_ = resp.Body.Close()
		if err != nil {
			return "", fmt.Errorf("read token: %w", err)
		}
		status = resp.StatusCode
		if status/100 == 5 && attempt < c.attempts {
			time.Sleep(time.Duration(attempt) * time.Second)
			continue
		}
		break
	}

	if status/100 != 2 {
		return "", fmt.Errorf("EOS token returned HTTP %d for %s", status, dep.DeploymentID)
	}

	var body struct {
		AccessToken string `json:"access_token"`
		TokenType   string `json:"token_type"`
		ExpiresIn   int    `json:"expires_in"`
	}
	if err := json.Unmarshal(raw, &body); err != nil {
		return "", fmt.Errorf("decode token: %w", err)
	}
	if body.AccessToken == "" || body.ExpiresIn <= 0 {
		return "", fmt.Errorf("EOS token response is missing access_token or expires_in")
	}

	c.mu.Lock()
	c.tokens[dep.DeploymentID] = cachedToken{
		value:     body.AccessToken,
		expiresAt: time.Now().Add(time.Duration(body.ExpiresIn) * time.Second),
	}
	c.mu.Unlock()

	return body.AccessToken, nil
}

// parseFilter unwraps the two envelope shapes Epic's regions send. The
// "wrapped in response" case is rarer but shows up from EU sometimes and
// costs one branch to handle here rather than at every call site.
func parseFilter(raw []byte) (FilterResponse, error) {
	var envelope struct {
		Sessions   []map[string]any `json:"sessions"`
		Pagination struct {
			TotalCount int `json:"totalCount"`
		} `json:"pagination"`
		Response *struct {
			Sessions   []map[string]any `json:"sessions"`
			Pagination struct {
				TotalCount int `json:"totalCount"`
			} `json:"pagination"`
		} `json:"response,omitempty"`
	}
	if err := json.Unmarshal(raw, &envelope); err != nil {
		return FilterResponse{}, fmt.Errorf("decode filter response: %w (body: %s)", err, snippet(raw))
	}

	if envelope.Response != nil {
		return FilterResponse{
			Sessions:   envelope.Response.Sessions,
			TotalCount: envelope.Response.Pagination.TotalCount,
		}, nil
	}
	return FilterResponse{
		Sessions:   envelope.Sessions,
		TotalCount: envelope.Pagination.TotalCount,
	}, nil
}

// redact blanks the two secrets out of anything that heads to a log — Epic's
// 400 body sometimes echoes the URL and its Authorization header verbatim,
// and neither is safe to write to disk.
func redact(dep Deployment, s string) string {
	if dep.ClientID != "" {
		s = strings.ReplaceAll(s, dep.ClientID, "[client_id]")
	}
	if dep.ClientSecret != "" {
		s = strings.ReplaceAll(s, dep.ClientSecret, "[client_secret]")
	}
	return s
}

// snippet trims a body preview into something a log line can carry — enough
// to identify the shape, not enough to blow the line width or leak more than
// the redactor caught.
func snippet(b []byte) string {
	const max = 240
	if len(b) <= max {
		return string(b)
	}
	return string(b[:max]) + "…(+" + strconv.Itoa(len(b)-max) + " bytes)"
}
