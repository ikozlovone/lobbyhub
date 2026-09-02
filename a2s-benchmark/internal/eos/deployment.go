// Package eos pulls live session lists from Epic Online Services matchmaking
// for games with no Steam-side server registration.
//
// ARK: Survival Ascended is the case that opened this door: UE5 with EAC/EOS
// networking, no answer on any port to Valve's A2S query. The rest of the
// sweeper does not care — from cmd/a2s-benchmark's point of view an EOS game
// is one where the "read state for this row" step calls into this package
// instead of the a2s or slp clients, and everything downstream (server_states
// writer, ClickHouse writer, per-game counters) is the same as for A2S.
//
// Session lists come one page at a time from POST /matchmaking/v1/{deploy}/
// filter; nothing here talks to the database.
package eos

import (
	"fmt"
	"os"
	"strings"
)

// Deployment is the credential triple one game keys through — Basic auth's
// user and password, and the deployment id the token is scoped to. The three
// travel together everywhere; a named struct here means losing one is a
// compile error rather than a mystery 401 at runtime.
type Deployment struct {
	Slug         string
	DeploymentID string
	ClientID     string
	ClientSecret string
}

// ResolveFromEnv reads the three env vars a game's credentials live in and
// refuses a blank triple with a message that says which variables to set.
//
// The env-var naming is symmetrical with the PHP side (config/services.php's
// eos.deployments block) so an operator setting `EOS_ARK_SURVIVAL_ASCENDED_*`
// in .env has one place for both. Slug goes to upper snake — dashes to
// underscores — and prefixed with `EOS_`.
func ResolveFromEnv(slug string) (Deployment, error) {
	env := envKey(slug)

	d := Deployment{
		Slug:         slug,
		DeploymentID: strings.TrimSpace(os.Getenv("EOS_" + env + "_DEPLOYMENT_ID")),
		ClientID:     strings.TrimSpace(os.Getenv("EOS_" + env + "_CLIENT_ID")),
		ClientSecret: strings.TrimSpace(os.Getenv("EOS_" + env + "_CLIENT_SECRET")),
	}

	if d.DeploymentID == "" || d.ClientID == "" || d.ClientSecret == "" {
		return Deployment{}, fmt.Errorf(
			"EOS credentials for %s are not configured; set EOS_%s_DEPLOYMENT_ID, EOS_%s_CLIENT_ID and EOS_%s_CLIENT_SECRET",
			slug, env, env, env,
		)
	}

	return d, nil
}

// envKey turns `ark-survival-ascended` into `ARK_SURVIVAL_ASCENDED`.
func envKey(slug string) string {
	return strings.ToUpper(strings.ReplaceAll(slug, "-", "_"))
}
