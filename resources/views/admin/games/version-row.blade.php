{{--
    One version. Fewer fields than a mode because the table has fewer: a version
    page is a filter with a release date, not a piece of copy.
--}}
<div class="row-card">
    @if($version)
        <input type="hidden" name="versions[{{ $i }}][id]" value="{{ $version->id }}">
    @endif
    <div class="row-grid dated">
        <label class="field">
            <span class="l">Name</span>
            <input type="text" name="versions[{{ $i }}][name]" value="{{ old("versions.$i.name", $version?->name) }}" maxlength="64" placeholder="1.21">
            @error("versions.$i.name")<div class="bad-field">{{ $message }}</div>@enderror
        </label>
        <label class="field">
            <span class="l">Slug</span>
            {{-- Dots are not URL-friendly, so 1.21 lives at /version/1-21. --}}
            <input type="text" name="versions[{{ $i }}][slug]" value="{{ old("versions.$i.slug", $version?->slug) }}" maxlength="64" placeholder="1-21">
            @error("versions.$i.slug")<div class="bad-field">{{ $message }}</div>@enderror
        </label>
        <label class="field">
            <span class="l">Order</span>
            <input type="number" name="versions[{{ $i }}][sort_order]" value="{{ old("versions.$i.sort_order", $version?->sort_order ?? 0) }}" min="0" max="65535">
            @error("versions.$i.sort_order")<div class="bad-field">{{ $message }}</div>@enderror
        </label>
        <label class="field">
            <span class="l">Released</span>
            <input type="date" name="versions[{{ $i }}][released_at]" value="{{ old("versions.$i.released_at", $version?->released_at?->toDateString()) }}">
            @error("versions.$i.released_at")<div class="bad-field">{{ $message }}</div>@enderror
        </label>
        <div class="check">
            <input type="hidden" name="versions[{{ $i }}][is_active]" value="0">
            <input type="checkbox" id="version-{{ $i }}-active" name="versions[{{ $i }}][is_active]" value="1"
                   @checked(old("versions.$i.is_active", $version?->is_active ?? true))>
            <label for="version-{{ $i }}-active" class="muted">Shown</label>
        </div>
        @if($version)
            <div class="check">
                <input type="checkbox" id="version-{{ $i }}-delete" name="versions[{{ $i }}][delete]" value="1">
                {{-- Servers pinned to it are not deleted; the column is nulled. --}}
                <label for="version-{{ $i }}-delete" class="muted">
                    Delete<span class="subtle"> ({{ $version->servers_count }} servers)</span>
                </label>
            </div>
        @else
            {{-- Keeps a blank row's columns lined up with the saved ones above. --}}
            <div></div>
        @endif
    </div>
</div>
