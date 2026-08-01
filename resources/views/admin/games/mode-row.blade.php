{{--
    One mode: the fields that make the facet page, and the ones search engines
    read, folded away because they are almost always empty.
--}}
<div class="row-card">
    @if($mode)
        <input type="hidden" name="modes[{{ $i }}][id]" value="{{ $mode->id }}">
    @endif
    <div class="row-grid">
        <label class="field">
            <span class="l">Name</span>
            <input type="text" name="modes[{{ $i }}][name]" value="{{ old("modes.$i.name", $mode?->name) }}">
            @error("modes.$i.name")<div class="bad-field">{{ $message }}</div>@enderror
        </label>
        <label class="field">
            <span class="l">Slug</span>
            <input type="text" name="modes[{{ $i }}][slug]" value="{{ old("modes.$i.slug", $mode?->slug) }}">
            @error("modes.$i.slug")<div class="bad-field">{{ $message }}</div>@enderror
        </label>
        <label class="field">
            <span class="l">Order</span>
            <input type="number" name="modes[{{ $i }}][sort_order]" value="{{ old("modes.$i.sort_order", $mode?->sort_order ?? 0) }}" min="0" max="65535">
            @error("modes.$i.sort_order")<div class="bad-field">{{ $message }}</div>@enderror
        </label>
        <div class="check">
            <input type="hidden" name="modes[{{ $i }}][is_active]" value="0">
            <input type="checkbox" id="mode-{{ $i }}-active" name="modes[{{ $i }}][is_active]" value="1"
                   @checked(old("modes.$i.is_active", $mode?->is_active ?? true))>
            <label for="mode-{{ $i }}-active" class="muted">Shown</label>
        </div>
        @if($mode)
            <div class="check">
                <input type="checkbox" id="mode-{{ $i }}-delete" name="modes[{{ $i }}][delete]" value="1">
                {{-- Servers keep existing; they simply stop carrying this tag. --}}
                <label for="mode-{{ $i }}-delete" class="muted">
                    Delete<span class="subtle"> ({{ $mode->servers_count }} servers tagged)</span>
                </label>
            </div>
        @else
            {{-- Keeps a blank row's columns lined up with the saved ones above. --}}
            <div></div>
        @endif
    </div>

    <details @if($errors->has("modes.$i.description") || $errors->has("modes.$i.meta_title") || $errors->has("modes.$i.meta_description")) open @endif>
        <summary>Description and search engines</summary>
        <div class="fields one">
            <label class="field">
                <span class="l">Description</span>
                <textarea name="modes[{{ $i }}][description]" rows="2">{{ old("modes.$i.description", $mode?->description) }}</textarea>
                @error("modes.$i.description")<div class="bad-field">{{ $message }}</div>@enderror
            </label>
            <label class="field">
                <span class="l">Meta title</span>
                <input type="text" name="modes[{{ $i }}][meta_title]" value="{{ old("modes.$i.meta_title", $mode?->meta_title) }}" maxlength="255">
                @error("modes.$i.meta_title")<div class="bad-field">{{ $message }}</div>@enderror
            </label>
            <label class="field">
                <span class="l">Meta description</span>
                <textarea name="modes[{{ $i }}][meta_description]" rows="2" maxlength="320">{{ old("modes.$i.meta_description", $mode?->meta_description) }}</textarea>
                @error("modes.$i.meta_description")<div class="bad-field">{{ $message }}</div>@enderror
            </label>
        </div>
    </details>
</div>
