import { ImageResponse } from 'next/og'

/**
 * The card social networks and chat clients show when the home page is pasted.
 *
 * Generated rather than committed as a PNG: the wordmark and the two brand
 * colours are the whole design, and a checked-in image is one more thing to
 * forget when they change. Next renders this once at build time and serves it
 * as a static file, so it costs nothing per request.
 *
 * No webfont is loaded on purpose — fetching Orbitron here would add a network
 * dependency to the build for a 1200x630 image that reads perfectly well in the
 * system sans. Twitter's summary_large_image wants 1200x630 too, so one image
 * serves both.
 */
export const size = { width: 1200, height: 630 }
export const contentType = 'image/png'
export const alt = 'LobbyHub — find the best game servers'

export default function OpengraphImage() {
  return new ImageResponse(
    (
      <div
        style={{
          width: '100%',
          height: '100%',
          display: 'flex',
          flexDirection: 'column',
          justifyContent: 'center',
          padding: '0 90px',
          background: '#0b1220',
          color: '#f1f5f9',
        }}
      >
        <div style={{ display: 'flex', fontSize: 44, fontWeight: 800, letterSpacing: -1 }}>
          <span>LOBBY</span>
          <span style={{ color: '#16a34a' }}>HUB</span>
        </div>

        <div
          style={{
            marginTop: 28,
            fontSize: 76,
            fontWeight: 800,
            lineHeight: 1.1,
            letterSpacing: -2,
            maxWidth: 900,
          }}
        >
          Find the Best Game Servers
        </div>

        <div style={{ marginTop: 28, fontSize: 30, color: '#94a3b8', maxWidth: 880 }}>
          Rust, Minecraft, Counter-Strike 2, DayZ and more — player counts and uptime from our own
          checks.
        </div>

        <div style={{ display: 'flex', marginTop: 40, height: 8, width: 220, background: '#16a34a' }} />
      </div>
    ),
    size,
  )
}
