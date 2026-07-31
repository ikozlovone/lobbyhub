@php
    $ago = fn (?string $stamp) => $stamp ? \Illuminate\Support\Carbon::parse($stamp)->diffForHumans() : '—';
@endphp

<x-admin.layout title="Monitoring" active="monitoring">
    <h2>Catalog</h2>
    <div class="cards">
        <div class="card">
            <div class="v">{{ number_format($statuses['total']) }}</div>
            <div class="k">active servers</div>
            @if($statuses['inactive'] > 0)
                <div class="n">{{ number_format($statuses['inactive']) }} delisted</div>
            @endif
        </div>
        <div class="card">
            <div class="v" style="color: var(--online)">{{ number_format($statuses['online']) }}</div>
            <div class="k">online</div>
        </div>
        <div class="card">
            <div class="v" style="color: var(--offline)">{{ number_format($statuses['offline']) }}</div>
            <div class="k">offline</div>
        </div>
        <div class="card">
            <div class="v" style="color: var(--accent)">{{ number_format($statuses['unknown']) }}</div>
            <div class="k">unknown</div>
            {{-- Imported from Steam and not reached yet: invisible on the site
                 until the monitor confirms them, so a number that stays put is
                 a queue that is not draining. --}}
            <div class="n">awaiting a first check</div>
        </div>
        <div class="card">
            <div class="v">{{ number_format($statuses['never_queried']) }}</div>
            <div class="k">never queried</div>
        </div>
    </div>

    <h2>Throughput</h2>
    <div class="cards">
        <div class="card">
            <div class="v">{{ $throughput['ratio'] !== null ? $throughput['ratio'].'%' : '—' }}</div>
            <div class="k">of the intended cadence</div>
            <div class="n">
                {{ number_format($throughput['actual_last_hour']) }} checks in the last
                {{ $throughput['window_minutes'] }} min, {{ number_format($throughput['expected_hourly']) }} expected hourly
            </div>
        </div>
        <div class="card">
            <div class="v">{{ number_format($throughput['due_now']) }}</div>
            <div class="k">due now</div>
            <div class="n">oldest waiting {{ $ago($throughput['oldest_due_at']) }}</div>
        </div>
        <div class="card">
            <div class="v">{{ number_format($throughput['batch_size']) }}</div>
            <div class="k">batch size</div>
            <div class="n">dispatched every minute</div>
        </div>
    </div>

    <h2>Checks, last 24 hours</h2>
    <div class="cards">
        <div class="card">
            <div class="v">{{ $timings['avg_latency_ms'] !== null ? $timings['avg_latency_ms'].' ms' : '—' }}</div>
            <div class="k">average answer time</div>
            <div class="n">slowest {{ $timings['max_latency_ms'] !== null ? $timings['max_latency_ms'].' ms' : '—' }}</div>
        </div>
        <div class="card">
            <div class="v">{{ number_format($timings['samples_24h']) }}</div>
            <div class="k">checks recorded</div>
            <div class="n">{{ $timings['checks_per_server_24h'] ?? '—' }} per server</div>
        </div>
        <div class="card">
            <div class="v">{{ $timings['failure_rate'] !== null ? $timings['failure_rate'].'%' : '—' }}</div>
            <div class="k">failed to answer</div>
            <div class="n">{{ number_format($timings['failures_24h']) }} of {{ number_format($timings['samples_24h']) }}</div>
        </div>
    </div>

    <h2>By game</h2>
    <div class="panel">
        <table>
            <thead>
                <tr>
                    <th>Game</th>
                    <th>Protocol</th>
                    <th class="num">Servers</th>
                    <th class="num">Online</th>
                    <th class="num">Offline</th>
                    <th class="num">Unknown</th>
                    <th class="num">Due</th>
                </tr>
            </thead>
            <tbody>
                @forelse($games as $game)
                    <tr>
                        <td>{{ $game->name }}</td>
                        <td class="subtle">{{ $game->query_protocol->value ?? $game->query_protocol }}</td>
                        <td class="num">{{ number_format((int) $game->total) }}</td>
                        <td class="num" style="color: var(--online)">{{ number_format((int) $game->online) }}</td>
                        <td class="num muted">{{ number_format((int) $game->offline) }}</td>
                        <td class="num" style="color: {{ (int) $game->unknown > 0 ? 'var(--accent)' : 'inherit' }}">
                            {{ number_format((int) $game->unknown) }}
                        </td>
                        <td class="num muted">{{ number_format((int) $game->due) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">No games.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="cols">
        <div>
            <h2>Slowest to answer</h2>
            <div class="panel">
                <table>
                    <thead>
                        <tr><th>Server</th><th>Game</th><th class="num">Average</th><th class="num">Checks</th></tr>
                    </thead>
                    <tbody>
                        @forelse($slowest as $server)
                            <tr>
                                <td class="wide">{{ $server->name }}</td>
                                <td class="subtle">{{ $server->game }}</td>
                                <td class="num">{{ (int) round((float) $server->avg_latency) }} ms</td>
                                <td class="num muted">{{ (int) $server->samples }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="empty">No answered checks in the last day.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>
            <h2>Down the longest</h2>
            <div class="panel">
                <table>
                    <thead>
                        <tr><th>Server</th><th>Game</th><th>Last online</th><th class="num">Failures</th></tr>
                    </thead>
                    <tbody>
                        @forelse($offline as $server)
                            <tr>
                                <td class="wide">{{ $server->name }}</td>
                                <td class="subtle">{{ $server->game?->name }}</td>
                                <td class="muted">{{ $server->last_online_at?->diffForHumans() ?? 'never' }}</td>
                                <td class="num muted">{{ (int) $server->failed_queries_count }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="empty">Nothing offline.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-admin.layout>
