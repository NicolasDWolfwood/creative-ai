import {
    ArrowUpRight,
    AudioLines,
    AudioWaveform,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    createIcons,
    Disc3,
    Headphones,
    Heart,
    LayoutGrid,
    Menu,
    MoveDown,
    Pause,
    Play,
    Repeat,
    Shuffle,
    SkipBack,
    SkipForward,
    X,
} from 'lucide';

const icons = {
    ArrowUpRight,
    AudioLines,
    AudioWaveform,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    Disc3,
    Headphones,
    Heart,
    LayoutGrid,
    Menu,
    MoveDown,
    Pause,
    Play,
    Repeat,
    Shuffle,
    SkipBack,
    SkipForward,
    X,
};

class CreativePlayer {
    constructor(payload) {
        this.playlists = payload.playlists || [];
        this.playlistIndex = 0;
        this.trackIndex = 0;
        this.isShuffle = false;
        this.isRepeat = false;
        this.queue = [];
        this.restoreTime = 0;
        this.audio = document.querySelector('[data-player-audio]');
        this.audio.preload = 'metadata';
        this.elements = this.collectElements();
        this.restoreState();
        this.bind();
        this.renderPlaylists();
        this.drawIdleVisualizer();
        this.loadTrack(false);
    }

    collectElements() {
        return {
            player: document.querySelector('[data-player]'),
            playlistPicker: document.querySelector('[data-playlist-picker]'),
            playlistTrigger: document.querySelector('[data-playlist-trigger]'),
            playlistTriggerCover: document.querySelector('[data-playlist-trigger-cover]'),
            playlistTriggerLabel: document.querySelector('[data-playlist-trigger-label]'),
            playlistMenu: document.querySelector('[data-playlist-menu]'),
            trackPicker: document.querySelector('[data-track-picker]'),
            trackTrigger: document.querySelector('[data-track-trigger]'),
            trackTriggerCover: document.querySelector('[data-track-trigger-cover]'),
            trackTriggerLabel: document.querySelector('[data-track-trigger-label]'),
            trackMenu: document.querySelector('[data-track-menu]'),
            title: document.querySelector('[data-track-title]'),
            artist: document.querySelector('[data-track-artist]'),
            art: document.querySelector('[data-player-art]'),
            play: document.querySelector('[data-play]'),
            prev: document.querySelector('[data-prev]'),
            next: document.querySelector('[data-next]'),
            shuffle: document.querySelector('[data-shuffle]'),
            repeat: document.querySelector('[data-repeat]'),
            seek: document.querySelector('[data-seek]'),
            volume: document.querySelector('[data-volume]'),
            currentTime: document.querySelector('[data-current-time]'),
            duration: document.querySelector('[data-duration]'),
            collapses: document.querySelectorAll('[data-player-collapse]'),
            canvas: document.querySelector('[data-visualizer]'),
            status: document.querySelector('[data-player-status]'),
        };
    }

    bind() {
        this.elements.play?.addEventListener('click', () => this.togglePlay());
        this.elements.prev?.addEventListener('click', () => this.previous());
        this.elements.next?.addEventListener('click', () => this.next());
        this.elements.shuffle?.addEventListener('click', () => this.toggleShuffle());
        this.elements.repeat?.addEventListener('click', () => this.toggleRepeat());
        this.elements.seek?.addEventListener('input', () => this.seek());
        this.elements.volume?.addEventListener('input', () => this.setVolume());
        this.elements.collapses.forEach((button) => button.addEventListener('click', () => this.setCollapsed(!this.elements.player.classList.contains('collapsed'))));
        this.elements.playlistTrigger?.addEventListener('click', () => {
            this.setLibraryOpen(this.elements.playlistMenu?.hidden ?? true);
        });
        this.elements.playlistTrigger?.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                this.setLibraryOpen(false);
                return;
            }
            if (event.key === 'Tab' && event.shiftKey && !this.elements.playlistMenu?.hidden) {
                requestAnimationFrame(() => this.setLibraryOpen(false));
                return;
            }
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;

            event.preventDefault();
            const targetIndex = event.key === 'Home'
                ? 0
                : event.key === 'End'
                    ? Math.max(0, this.playlists.length - 1)
                    : this.playlistIndex;
            this.setLibraryOpen(true, targetIndex);
        });
        this.elements.playlistMenu?.addEventListener('click', (event) => this.chooseLibraryOption(event));
        this.elements.playlistMenu?.addEventListener('keydown', (event) => this.handleLibraryKeydown(event));
        this.elements.trackTrigger?.addEventListener('click', () => {
            this.setTrackMenuOpen(this.elements.trackMenu?.hidden ?? true);
        });
        this.elements.trackTrigger?.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                this.setTrackMenuOpen(false);
                return;
            }
            if (event.key === 'Tab' && event.shiftKey && !this.elements.trackMenu?.hidden) {
                requestAnimationFrame(() => this.setTrackMenuOpen(false));
                return;
            }
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;

            event.preventDefault();
            const targetIndex = event.key === 'Home'
                ? 0
                : event.key === 'End'
                    ? Math.max(0, (this.currentPlaylist()?.tracks?.length || 1) - 1)
                    : this.trackIndex;
            this.setTrackMenuOpen(true, targetIndex);
        });
        this.elements.trackMenu?.addEventListener('click', (event) => this.chooseTrackOption(event));
        this.elements.trackMenu?.addEventListener('keydown', (event) => this.handleTrackMenuKeydown(event));
        document.addEventListener('click', (event) => {
            if (!(event.target instanceof Node)) return;
            if (!this.elements.playlistPicker?.contains(event.target)) this.setLibraryOpen(false);
            if (!this.elements.trackPicker?.contains(event.target)) this.setTrackMenuOpen(false);
        });

        this.audio.addEventListener('timeupdate', () => { this.updateProgress(); this.saveState(); });
        this.audio.addEventListener('loadedmetadata', () => { if (this.restoreTime > 0 && this.restoreTime < this.audio.duration) this.audio.currentTime = this.restoreTime; this.restoreTime = 0; this.updateProgress(); });
        this.audio.addEventListener('ended', () => (this.isRepeat ? this.replay() : this.next(true)));
    }

    bindPageControls(signal) {
        document.querySelectorAll('[data-playlist-id]').forEach((button) => {
            button.addEventListener('click', () => this.playPlaylist(button.dataset.playlistId), { signal });
        });
        document.querySelectorAll('[data-play-track-id]').forEach((button) => button.addEventListener('click', () => this.playTrack(button.dataset.playTrackId, button.dataset.trackSourceId), { signal }));
        document.querySelectorAll('[data-queue-track-id]').forEach((button) => button.addEventListener('click', () => this.enqueue(button.dataset.queueTrackId, button.dataset.trackSourceId), { signal }));

        document.querySelector('[data-player-focus]')?.addEventListener('click', () => {
            this.setCollapsed(false);
            this.elements.play?.focus();
        }, { signal });

        this.markActivePlaylist();
    }

    restoreState() {
        let state = {};

        try {
            state = JSON.parse(localStorage.getItem('creative-ai-player') || '{}');
        } catch {
            localStorage.removeItem('creative-ai-player');
        }
        this.playlistIndex = Number.isInteger(state.playlistIndex) ? state.playlistIndex : 0;
        this.trackIndex = Number.isInteger(state.trackIndex) ? state.trackIndex : 0;
        this.audio.volume = typeof state.volume === 'number' ? state.volume : 0.85;
        this.elements.volume.value = this.audio.volume;
        this.isShuffle = Boolean(state.isShuffle);
        this.isRepeat = Boolean(state.isRepeat);
        this.queue = Array.isArray(state.queue) ? state.queue : [];
        this.restoreTime = Number(state.currentTime || 0);
        this.restoredPlaylistId = state.playlistId;
        this.restoredTrackId = state.trackId;
        this.elements.shuffle?.classList.toggle('active', this.isShuffle);
        this.elements.repeat?.classList.toggle('active', this.isRepeat);
        this.elements.shuffle?.setAttribute('aria-pressed', String(this.isShuffle));
        this.elements.repeat?.setAttribute('aria-pressed', String(this.isRepeat));
        this.setCollapsed(this.elements.player?.classList.contains('collapsed') ?? true);
    }

    saveState() {
        localStorage.setItem('creative-ai-player', JSON.stringify({
            playlistIndex: this.playlistIndex,
            trackIndex: this.trackIndex,
            volume: this.audio.volume,
            isShuffle: this.isShuffle,
            isRepeat: this.isRepeat,
            playlistId: this.currentPlaylist()?.id,
            trackId: this.currentTrack()?.id,
            currentTime: this.audio.currentTime || this.restoreTime || 0,
            queue: this.queue,
        }));
    }

    renderPlaylists(preferredPlaylistId = this.restoredPlaylistId, preferredTrackId = this.restoredTrackId) {
        if (!this.elements.playlistMenu) return;

        this.playlistIndex = Math.max(0, Math.min(this.playlistIndex, Math.max(0, this.playlists.length - 1)));
        const restoredPlaylist = this.playlists.findIndex((playlist) => String(playlist.id) === String(preferredPlaylistId));
        if (restoredPlaylist >= 0) this.playlistIndex = restoredPlaylist;
        const restoredTrack = this.currentPlaylist()?.tracks?.findIndex((track) => String(track.id) === String(preferredTrackId));
        if (restoredTrack >= 0) this.trackIndex = restoredTrack;

        this.elements.playlistMenu.replaceChildren();
        const labels = { album: 'Albums', playlist: 'Playlists', track: 'Tracks' };
        ['album', 'playlist', 'track'].forEach((type) => {
            const matches = this.playlists
                .map((playlist, index) => ({ playlist, index }))
                .filter(({ playlist }) => (playlist.type || 'playlist') === type);
            if (!matches.length) return;

            const group = document.createElement('div');
            const heading = document.createElement('span');
            const headingId = `player-library-${type}-heading`;
            group.className = 'player-library-group';
            group.setAttribute('role', 'group');
            group.setAttribute('aria-labelledby', headingId);
            heading.className = 'player-library-heading';
            heading.id = headingId;
            heading.textContent = labels[type];
            group.append(heading);

            matches.forEach(({ playlist, index }) => {
                const option = document.createElement('button');
                const cover = document.createElement('span');
                const label = document.createElement('span');
                option.className = 'player-library-option';
                option.type = 'button';
                option.dataset.playlistOption = '';
                option.dataset.playlistIndex = String(index);
                cover.className = 'player-library-cover';
                cover.setAttribute('aria-hidden', 'true');
                label.textContent = this.libraryLabel(playlist);
                this.renderLibraryCover(cover, playlist);
                option.append(cover, label);
                group.append(option);
            });
            this.elements.playlistMenu.append(group);
        });

        this.elements.playlistTrigger.disabled = this.playlists.length === 0;
        this.syncLibrarySelection();
        this.renderTracks();
        this.markActivePlaylist();
    }

    libraryLabel(playlist) {
        const title = String(playlist?.title || 'Untitled');
        const artist = typeof playlist?.artist === 'string' ? playlist.artist.trim() : '';

        return playlist?.type === 'album' && artist ? `${artist} - ${title}` : title;
    }

    renderLibraryCover(container, playlist) {
        if (!container) return;

        container.replaceChildren();
        container.dataset.sourceType = playlist?.type || 'track';
        if (!playlist?.cover) return;

        const image = document.createElement('img');
        image.src = playlist.cover;
        image.alt = '';
        image.loading = 'lazy';
        image.addEventListener('error', () => image.remove(), { once: true });
        container.append(image);
    }

    renderTracks() {
        if (!this.elements.trackPicker || !this.elements.trackTrigger || !this.elements.trackMenu) return;

        const playlist = this.currentPlaylist();
        const tracks = playlist?.tracks || [];
        const isAlbum = playlist?.type === 'album';
        const restoreFocus = this.elements.trackMenu.contains(document.activeElement);
        const trackPickerHadFocus = this.elements.trackPicker.contains(document.activeElement);
        this.setTrackMenuOpen(false);
        this.elements.trackPicker.hidden = !isAlbum;
        this.elements.trackTrigger.disabled = tracks.length === 0;
        this.elements.trackMenu.dataset.sourceId = playlist ? String(playlist.id) : '';
        this.elements.trackMenu.replaceChildren();

        if (!isAlbum) {
            if (trackPickerHadFocus) requestAnimationFrame(() => this.elements.playlistTrigger?.focus());
            return;
        }

        const group = document.createElement('div');
        const heading = document.createElement('span');
        group.className = 'player-library-group';
        group.setAttribute('role', 'group');
        group.setAttribute('aria-labelledby', 'player-track-heading');
        heading.className = 'player-library-heading';
        heading.id = 'player-track-heading';
        heading.textContent = 'Tracks';
        group.append(heading);

        tracks.forEach((track, index) => {
            const option = document.createElement('button');
            const cover = document.createElement('span');
            const copy = document.createElement('span');
            const titleLabel = document.createElement('strong');
            const artistLabel = document.createElement('span');
            const title = String(track?.title || `Track ${index + 1}`);
            const artist = String(track?.artist || playlist.artist || '').trim();
            const sequence = this.trackSequence(track, index);
            option.className = 'player-library-option';
            option.type = 'button';
            option.dataset.trackOption = '';
            option.dataset.trackIndex = String(index);
            option.setAttribute('aria-label', `Track ${sequence}, ${title}${artist ? `, by ${artist}` : ''}`);
            cover.className = 'player-library-cover';
            cover.setAttribute('aria-hidden', 'true');
            copy.className = 'player-track-copy';
            titleLabel.textContent = `${sequence} · ${title}`;
            artistLabel.textContent = artist;
            copy.append(titleLabel);
            if (artist) copy.append(artistLabel);
            this.renderLibraryCover(cover, track);
            option.append(cover, copy);
            group.append(option);
        });
        this.elements.trackMenu.append(group);
        this.elements.trackMenu.setAttribute('aria-label', `Tracks on ${this.libraryLabel(playlist)}`);
        this.syncTrackSelection();
        if (restoreFocus) requestAnimationFrame(() => this.elements.trackTrigger?.focus());
    }

    trackSequence(track, index) {
        const discNumber = Number(track?.disc_number);
        const trackNumber = Number(track?.track_number);
        if (Number.isInteger(trackNumber) && trackNumber > 0) {
            return Number.isInteger(discNumber) && discNumber > 1
                ? `${discNumber}.${trackNumber}`
                : String(trackNumber);
        }

        return String(index + 1);
    }

    syncTrackSelection() {
        const playlist = this.currentPlaylist();
        const track = this.currentTrack();
        if (playlist?.type !== 'album') return;

        const title = String(track?.title || 'No tracks available');
        const label = track ? `${this.trackSequence(track, this.trackIndex)} · ${title}` : title;
        if (this.elements.trackTriggerLabel) {
            this.elements.trackTriggerLabel.textContent = label;
        }
        this.elements.trackTrigger?.setAttribute(
            'aria-label',
            track ? `Choose a track from ${playlist.title}. Current: ${label}` : label,
        );
        this.renderLibraryCover(this.elements.trackTriggerCover, track);
        this.elements.trackMenu?.querySelectorAll('[data-track-option]').forEach((option) => {
            const selected = Number(option.dataset.trackIndex) === this.trackIndex;
            option.classList.toggle('active', selected);
            if (selected) {
                option.setAttribute('aria-current', 'true');
            } else {
                option.removeAttribute('aria-current');
            }
        });
    }

    syncLibrarySelection() {
        const playlist = this.currentPlaylist();
        const label = playlist ? this.libraryLabel(playlist) : 'No music available';
        if (this.elements.playlistTriggerLabel) {
            this.elements.playlistTriggerLabel.textContent = label;
        }
        this.elements.playlistTrigger?.setAttribute(
            'aria-label',
            playlist ? `Choose music source. Current: ${label}` : label,
        );
        this.renderLibraryCover(this.elements.playlistTriggerCover, playlist);
        this.elements.playlistMenu?.querySelectorAll('[data-playlist-option]').forEach((option) => {
            const selected = Number(option.dataset.playlistIndex) === this.playlistIndex;
            option.classList.toggle('active', selected);
            if (selected) {
                option.setAttribute('aria-current', 'true');
            } else {
                option.removeAttribute('aria-current');
            }
        });
    }

    setLibraryOpen(open, focusIndex = null) {
        if (!this.elements.playlistMenu || !this.elements.playlistTrigger) return;

        const expanded = Boolean(open && this.playlists.length);
        if (expanded) this.setTrackMenuOpen(false);
        this.elements.playlistMenu.hidden = !expanded;
        this.elements.playlistTrigger.setAttribute('aria-expanded', String(expanded));
        this.elements.player?.classList.toggle('library-open', expanded);

        if (expanded && Number.isInteger(focusIndex)) {
            requestAnimationFrame(() => this.focusLibraryOption(focusIndex));
        }
    }

    focusLibraryOption(index) {
        const options = Array.from(this.elements.playlistMenu?.querySelectorAll('[data-playlist-option]') || []);
        const option = options.find((candidate) => Number(candidate.dataset.playlistIndex) === index);
        option?.focus();
    }

    chooseLibraryOption(event) {
        const option = event.target instanceof Element
            ? event.target.closest('[data-playlist-option]')
            : null;
        if (!option || !this.elements.playlistMenu?.contains(option)) return;

        this.selectPlaylist(Number(option.dataset.playlistIndex));
    }

    handleLibraryKeydown(event) {
        const option = event.target instanceof Element
            ? event.target.closest('[data-playlist-option]')
            : null;
        if (!option) return;
        const options = Array.from(this.elements.playlistMenu.querySelectorAll('[data-playlist-option]'));
        const current = options.indexOf(option);
        if (event.key === 'Escape') {
            event.preventDefault();
            this.setLibraryOpen(false);
            this.elements.playlistTrigger?.focus();
            return;
        }
        if (event.key === 'Tab') {
            const leavingMenu = (!event.shiftKey && current === options.length - 1)
                || (event.shiftKey && current === 0);
            if (leavingMenu) requestAnimationFrame(() => this.setLibraryOpen(false));
            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;

        event.preventDefault();
        const next = event.key === 'Home'
            ? 0
            : event.key === 'End'
                ? options.length - 1
                : (current + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
        options[next]?.focus();
    }

    setTrackMenuOpen(open, focusIndex = null) {
        if (!this.elements.trackMenu || !this.elements.trackTrigger) return;

        const playlist = this.currentPlaylist();
        const expanded = Boolean(open && playlist?.type === 'album' && playlist.tracks?.length);
        if (expanded) this.setLibraryOpen(false);
        this.elements.trackMenu.hidden = !expanded;
        this.elements.trackTrigger.setAttribute('aria-expanded', String(expanded));

        if (expanded && Number.isInteger(focusIndex)) {
            requestAnimationFrame(() => this.focusTrackOption(focusIndex));
        }
    }

    focusTrackOption(index) {
        const options = Array.from(this.elements.trackMenu?.querySelectorAll('[data-track-option]') || []);
        options.find((candidate) => Number(candidate.dataset.trackIndex) === index)?.focus();
    }

    chooseTrackOption(event) {
        const option = event.target instanceof Element
            ? event.target.closest('[data-track-option]')
            : null;
        if (!option || !this.elements.trackMenu?.contains(option)) return;

        this.selectTrack(Number(option.dataset.trackIndex));
    }

    handleTrackMenuKeydown(event) {
        const option = event.target instanceof Element
            ? event.target.closest('[data-track-option]')
            : null;
        if (!option) return;
        const options = Array.from(this.elements.trackMenu.querySelectorAll('[data-track-option]'));
        const current = options.indexOf(option);
        if (event.key === 'Escape') {
            event.preventDefault();
            this.setTrackMenuOpen(false);
            this.elements.trackTrigger?.focus();
            return;
        }
        if (event.key === 'Tab') {
            const leavingMenu = (!event.shiftKey && current === options.length - 1)
                || (event.shiftKey && current === 0);
            if (leavingMenu) requestAnimationFrame(() => this.setTrackMenuOpen(false));
            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;

        event.preventDefault();
        const next = event.key === 'Home'
            ? 0
            : event.key === 'End'
                ? options.length - 1
                : (current + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
        options[next]?.focus();
    }

    selectTrack(index) {
        const tracks = this.currentPlaylist()?.tracks || [];
        if (!Number.isInteger(index) || !tracks[index]) return;
        if (index === this.trackIndex) {
            this.setTrackMenuOpen(false);
            this.elements.trackTrigger?.focus();
            return;
        }

        this.trackIndex = index;
        this.loadTrack(false);
        this.setTrackMenuOpen(false);
        this.elements.trackTrigger?.focus();
        const track = this.currentTrack();
        if (track) this.announce(`Selected ${track.title}. Press play to start.`);
    }

    selectPlaylist(index) {
        if (!Number.isInteger(index) || !this.playlists[index]) return;
        if (index === this.playlistIndex) {
            this.setLibraryOpen(false);
            this.elements.playlistTrigger?.focus();
            return;
        }

        this.playlistIndex = index;
        this.trackIndex = 0;
        this.loadTrack(false);
        this.setLibraryOpen(false);
        this.elements.playlistTrigger?.focus();
        const track = this.currentTrack();
        if (track) this.announce(`Selected ${track.title}. Press play to start.`);
    }

    updateLibrary(payload) {
        const incoming = Array.isArray(payload.playlists) ? payload.playlists : [];
        const activePlaylist = this.currentPlaylist();
        const activeTrack = this.currentTrack();
        const hadTrack = Boolean(activeTrack);
        const playlists = [...incoming];

        if (activePlaylist && !playlists.some((playlist) => String(playlist.id) === String(activePlaylist.id))) {
            playlists.push(activePlaylist);
        }

        this.playlists = playlists;
        this.playlistIndex = Math.max(0, playlists.findIndex((playlist) => String(playlist.id) === String(activePlaylist?.id)));
        this.trackIndex = Math.max(0, this.currentPlaylist()?.tracks?.findIndex((track) => String(track.id) === String(activeTrack?.id)) ?? 0);
        this.setLibraryOpen(false);
        this.setTrackMenuOpen(false);
        this.renderPlaylists(activePlaylist?.id, activeTrack?.id);

        const selectedTrack = this.currentTrack();
        const activeTrackWasRemoved = hadTrack && String(selectedTrack?.id) !== String(activeTrack.id);
        if (selectedTrack && (!hadTrack || activeTrackWasRemoved)) this.loadTrack(false);
    }

    currentPlaylist() {
        return this.playlists[this.playlistIndex];
    }

    currentTrack() {
        return this.currentPlaylist()?.tracks?.[this.trackIndex];
    }

    loadTrack(autoplay) {
        const playlist = this.currentPlaylist();
        const renderedSourceId = this.elements.trackMenu?.dataset.sourceId ?? '';
        if (renderedSourceId !== String(playlist?.id ?? '')) this.renderTracks();
        if (!playlist || playlist.tracks.length === 0) {
            this.elements.title.textContent = 'No tracks published';
            this.elements.artist.textContent = 'Add music in the admin panel';
            this.syncLibrarySelection();
            this.syncTrackSelection();
            return;
        }

        this.trackIndex = Math.min(this.trackIndex, playlist.tracks.length - 1);
        const track = this.currentTrack();
        this.audio.src = track.url;
        this.elements.title.textContent = track.title;
        this.elements.artist.textContent = track.artist || playlist.title;
        this.elements.art.style.backgroundImage = track.cover ? `url("${track.cover}")` : '';
        this.syncLibrarySelection();
        this.syncTrackSelection();
        this.updatePlayIcon(false);
        this.markActivePlaylist();
        this.saveState();
        this.drawWaveform(track.waveform || []);

        if (autoplay) {
            this.announce(`Now playing ${track.title}${track.artist ? ` by ${track.artist}` : ''}.`);
            this.play();
        }
    }

    findTrack(trackId, preferredPlaylistId = null) {
        const preferredPlaylistIndex = this.playlists.findIndex((playlist) => String(playlist.id) === String(preferredPlaylistId));
        if (preferredPlaylistIndex >= 0) {
            const preferredTrackIndex = this.playlists[preferredPlaylistIndex].tracks.findIndex((track) => String(track.id) === String(trackId));
            if (preferredTrackIndex >= 0) return { playlistIndex: preferredPlaylistIndex, trackIndex: preferredTrackIndex };
        }

        for (let playlistIndex = 0; playlistIndex < this.playlists.length; playlistIndex++) {
            const trackIndex = this.playlists[playlistIndex].tracks.findIndex((track) => String(track.id) === String(trackId));
            if (trackIndex >= 0) return { playlistIndex, trackIndex };
        }
        return null;
    }

    playTrack(trackId, preferredPlaylistId = null) {
        const found = this.findTrack(trackId, preferredPlaylistId); if (!found) return;
        this.playlistIndex = found.playlistIndex; this.trackIndex = found.trackIndex; this.loadTrack(true);
    }

    enqueue(trackId, preferredPlaylistId = null) {
        const found = this.findTrack(trackId, preferredPlaylistId);
        if (!found) return;
        this.queue.push(String(trackId));
        this.saveState();
        const track = this.playlists[found.playlistIndex]?.tracks?.[found.trackIndex];
        this.announce(`${track?.title || 'Track'} added to queue. ${this.queue.length} queued.`);
    }

    drawWaveform(points) {
        const canvas = this.elements.canvas; if (!canvas || this.audioContext) return;
        if (!points.length) { this.drawIdleVisualizer(); return; }
        const context = canvas.getContext('2d'); const middle = canvas.height / 2;
        context.clearRect(0, 0, canvas.width, canvas.height); context.fillStyle = 'rgba(111, 231, 200, .7)';
        const width = canvas.width / points.length;
        points.forEach((value, index) => { const height = Math.max(1, value / 100 * canvas.height); context.fillRect(index * width, middle - height / 2, Math.max(1, width - 1), height); });
    }

    playPlaylist(playlistId) {
        const index = this.playlists.findIndex((playlist) => String(playlist.id) === String(playlistId));
        if (index < 0) return;

        this.playlistIndex = index;
        this.trackIndex = 0;
        this.loadTrack(true);
    }

    markActivePlaylist() {
        const playlist = this.currentPlaylist();
        document.querySelectorAll('[data-playlist-id]').forEach((button) => {
            button.classList.toggle('active', playlist && String(button.dataset.playlistId) === String(playlist.id));
        });
    }

    async togglePlay() {
        if (this.audio.paused) {
            await this.play();
        } else {
            this.audio.pause();
            this.updatePlayIcon(false);
        }
    }

    async play() {
        this.setupAudioContext();
        try {
            await this.audio.play();
            this.updatePlayIcon(true);
        } catch {
            this.updatePlayIcon(false);
        }
    }

    replay() {
        this.audio.currentTime = 0;
        this.play();
    }

    previous() {
        if (this.audio.currentTime > 3) {
            this.audio.currentTime = 0;
            return;
        }

        const playlist = this.currentPlaylist();
        if (!playlist) return;
        this.trackIndex = this.trackIndex - 1 < 0 ? playlist.tracks.length - 1 : this.trackIndex - 1;
        this.loadTrack(true);
    }

    next(autoplay = false) {
        if (this.queue.length) {
            const queued = this.queue.shift(); const found = this.findTrack(queued);
            if (found) { this.playlistIndex = found.playlistIndex; this.trackIndex = found.trackIndex; this.loadTrack(true); return; }
        }
        const playlist = this.currentPlaylist();
        if (!playlist) return;

        if (this.isShuffle && playlist.tracks.length > 1) {
            let nextIndex = this.trackIndex;
            while (nextIndex === this.trackIndex) {
                nextIndex = Math.floor(Math.random() * playlist.tracks.length);
            }
            this.trackIndex = nextIndex;
        } else {
            this.trackIndex = (this.trackIndex + 1) % playlist.tracks.length;
        }

        this.loadTrack(autoplay || !this.audio.paused);
    }

    toggleShuffle() {
        this.isShuffle = !this.isShuffle;
        this.elements.shuffle?.classList.toggle('active', this.isShuffle);
        this.elements.shuffle?.setAttribute('aria-pressed', String(this.isShuffle));
        this.announce(`Shuffle ${this.isShuffle ? 'on' : 'off'}.`);
        this.saveState();
    }

    toggleRepeat() {
        this.isRepeat = !this.isRepeat;
        this.elements.repeat?.classList.toggle('active', this.isRepeat);
        this.elements.repeat?.setAttribute('aria-pressed', String(this.isRepeat));
        this.announce(`Repeat ${this.isRepeat ? 'on' : 'off'}.`);
        this.saveState();
    }

    seek() {
        if (!this.audio.duration) return;
        this.audio.currentTime = (Number(this.elements.seek.value) / 100) * this.audio.duration;
    }

    setVolume() {
        this.audio.volume = Number(this.elements.volume.value);
        this.saveState();
    }

    updateProgress() {
        const duration = this.audio.duration || 0;
        this.elements.currentTime.textContent = this.formatTime(this.audio.currentTime || 0);
        this.elements.duration.textContent = this.formatTime(duration);
        this.elements.seek.value = duration ? String((this.audio.currentTime / duration) * 100) : '0';
    }

    updatePlayIcon(isPlaying) {
        this.elements.play.innerHTML = isPlaying ? '<i data-lucide="pause"></i>' : '<i data-lucide="play"></i>';
        this.elements.play.setAttribute('aria-label', isPlaying ? 'Pause' : 'Play');
        createIcons({ icons });
    }

    setCollapsed(collapsed) {
        this.elements.player?.classList.toggle('collapsed', collapsed);
        if (collapsed) {
            this.setLibraryOpen(false);
            this.setTrackMenuOpen(false);
        }
        this.elements.collapses.forEach((button) => {
            button.setAttribute('aria-expanded', String(!collapsed));
            button.setAttribute('aria-label', `${collapsed ? 'Expand' : 'Collapse'} music player`);
        });
    }

    announce(message) {
        if (this.elements.status) this.elements.status.textContent = message;
    }

    formatTime(seconds) {
        const minutes = Math.floor(seconds / 60);
        const rest = Math.floor(seconds % 60).toString().padStart(2, '0');
        return `${minutes}:${rest}`;
    }

    setupAudioContext() {
        if (this.audioContext || !this.elements.canvas) return;

        this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
        this.analyser = this.audioContext.createAnalyser();
        this.analyser.fftSize = 128;
        const source = this.audioContext.createMediaElementSource(this.audio);
        source.connect(this.analyser);
        this.analyser.connect(this.audioContext.destination);
        this.drawVisualizer();
    }

    drawIdleVisualizer() {
        const canvas = this.elements.canvas;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#141820';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#24d1b0';
        for (let i = 0; i < 34; i++) {
            const height = 8 + ((i * 7) % 32);
            ctx.fillRect(i * 16, canvas.height - height - 10, 6, height);
        }
    }

    drawVisualizer() {
        const canvas = this.elements.canvas;
        const ctx = canvas.getContext('2d');
        const data = new Uint8Array(this.analyser.frequencyBinCount);

        const draw = () => {
            requestAnimationFrame(draw);
            this.analyser.getByteFrequencyData(data);
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = '#07080b';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            const width = canvas.width / data.length;
            data.forEach((value, index) => {
                const barHeight = Math.max(4, (value / 255) * (canvas.height - 16));
                ctx.fillStyle = index % 5 === 0 ? '#f3b84e' : '#24d1b0';
                ctx.fillRect(index * width, canvas.height - barHeight - 8, Math.max(3, width - 2), barHeight);
            });
        };

        draw();
    }
}

function setupLightbox(signal) {
    const panel = document.querySelector('[data-lightbox-panel]');
    if (!panel) return;

    const image = panel.querySelector('[data-lightbox-image]');
    const title = panel.querySelector('[data-lightbox-title]');
    const description = panel.querySelector('[data-lightbox-description]');
    const detail = panel.querySelector('[data-lightbox-detail]');
    const closeControl = panel.querySelector('[data-lightbox-close]');
    const previous = panel.querySelector('[data-lightbox-prev]');
    const next = panel.querySelector('[data-lightbox-next]');

    const items = () => {
        const triggers = [...document.querySelectorAll('[data-lightbox]')];

        return triggers.filter((trigger) => {
            const preferred = triggers.find((candidate) => candidate.dataset.full === trigger.dataset.full && candidate.dataset.detail)
                || triggers.find((candidate) => candidate.dataset.full === trigger.dataset.full);

            return trigger === preferred;
        });
    };
    let activeIndex = 0;
    let opener = null;
    const background = [...document.body.children].filter((element) => element !== panel && element.tagName !== 'SCRIPT');
    const previouslyInert = new Map();

    const show = (index, source = null) => {
        const availableItems = items();
        if (!availableItems.length) return;
        const hasMultipleItems = availableItems.length > 1;
        activeIndex = (index + availableItems.length) % availableItems.length;
        const trigger = source || availableItems[activeIndex];
        [previous, next].forEach((control) => {
            if (!control) return;
            control.hidden = !hasMultipleItems;
            control.disabled = !hasMultipleItems;
        });
        image.src = trigger.dataset.full;
        image.alt = trigger.dataset.alt || trigger.dataset.title || '';
        title.textContent = trigger.dataset.title || '';
        description.textContent = trigger.dataset.description || '';
        if (detail) {
            const detailUrl = trigger.dataset.detail || availableItems[activeIndex]?.dataset.detail || '';
            if (!detailUrl && document.activeElement === detail) closeControl?.focus();
            detail.hidden = !detailUrl;
            if (detailUrl) {
                detail.href = detailUrl;
            } else {
                detail.removeAttribute('href');
            }
        }
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element ? event.target.closest('[data-lightbox]') : null;
        if (!trigger) return;
        if (trigger instanceof HTMLAnchorElement) {
            if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
            event.preventDefault();
        }

        opener = trigger;
        const availableItems = items();
        const index = availableItems.findIndex((item) => item === trigger || item.dataset.full === trigger.dataset.full);
        show(Math.max(0, index), trigger);
        panel.classList.add('open');
        panel.setAttribute('aria-hidden', 'false');
        document.body.classList.add('lightbox-open');
        background.forEach((element) => {
            previouslyInert.set(element, element.inert);
            element.inert = true;
        });
        closeControl?.focus();
    }, { signal });

    const close = (restoreFocus = true) => {
        if (!panel.classList.contains('open')) return;
        panel.classList.remove('open');
        panel.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('lightbox-open');
        background.forEach((element) => { element.inert = previouslyInert.get(element) ?? false; });
        previouslyInert.clear();
        image.src = '';
        if (restoreFocus && opener?.isConnected) opener.focus();
        opener = null;
    };

    closeControl?.addEventListener('click', close, { signal });
    previous?.addEventListener('click', () => show(activeIndex - 1), { signal });
    next?.addEventListener('click', () => show(activeIndex + 1), { signal });
    panel.addEventListener('click', (event) => {
        if (event.target === panel) close();
    }, { signal });
    document.addEventListener('keydown', (event) => {
        if (!panel.classList.contains('open')) return;
        if (event.key === 'Escape') close();
        if (event.key === 'ArrowLeft') show(activeIndex - 1);
        if (event.key === 'ArrowRight') show(activeIndex + 1);
        if (event.key === 'Tab') {
            const focusable = [...panel.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])')];
            const first = focusable[0];
            const last = focusable.at(-1);
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        }
    }, { signal });

    signal.addEventListener('abort', () => close(false), { once: true });
}

function setupArtworkPreviewDeterrence(signal) {
    const preventCasualCopy = (event) => {
        const image = event.target instanceof Element
            ? event.target.closest('[data-artwork-preview-image]')
            : null;

        if (!image) return;

        event.preventDefault();
    };

    document.addEventListener('contextmenu', preventCasualCopy, { signal });
    document.addEventListener('dragstart', preventCasualCopy, { signal });
}

let artworkNavigationPending = false;

function setupArtworkNavigation(signal) {
    const viewer = document.querySelector('[data-artwork-viewer]');

    if (!viewer) return;

    viewer.addEventListener('click', (event) => {
        const link = event.target instanceof Element
            ? event.target.closest('a[data-artwork-previous][href], a[data-artwork-next][href]')
            : null;

        if (!link || !viewer.contains(link)) return;
        if (event.button !== 0 || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;

        artworkNavigationPending = true;
    }, { capture: true, signal });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        if (event.defaultPrevented || event.repeat || event.isComposing) return;
        if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;

        const target = event.target instanceof Element ? event.target : null;
        const formControl = target?.closest('input, textarea, select, option, button, [role="textbox"], [role="slider"], audio, video');

        if (formControl || (target instanceof HTMLElement && target.isContentEditable)) return;

        const openDialog = document.querySelector('dialog[open], [role="dialog"][aria-hidden="false"], [role="dialog"].open');

        if (document.body.classList.contains('lightbox-open') || openDialog) return;

        const selector = event.key === 'ArrowLeft'
            ? 'a[data-artwork-previous][href]'
            : 'a[data-artwork-next][href]';
        const link = viewer.querySelector(selector);

        if (!link || link.getAttribute('aria-disabled') === 'true') return;

        event.preventDefault();
        artworkNavigationPending = true;
        link.click();
    }, { signal });
}

function focusArtworkViewer() {
    const viewer = document.querySelector('[data-artwork-viewer]');

    if (!viewer) return;

    requestAnimationFrame(() => {
        if (!viewer.isConnected) return;

        viewer.scrollIntoView({ behavior: 'instant', block: 'start' });
        viewer.focus({ preventScroll: true });
    });
}

function setupGalleryPagination(signal) {
    const results = document.querySelector('[data-gallery-results]');
    const pagination = document.querySelector('[data-gallery-pagination]');
    const status = pagination?.querySelector('[data-gallery-load-status]');
    const count = document.querySelector('[data-gallery-count]');
    let loading = false;

    if (!results || !pagination) return;

    document.addEventListener('click', async (event) => {
        const link = event.target instanceof Element ? event.target.closest('[data-gallery-load-more]') : null;

        if (!link || !pagination.contains(link)) return;
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        event.preventDefault();
        if (loading) return;

        loading = true;
        link.setAttribute('aria-busy', 'true');
        link.setAttribute('aria-disabled', 'true');
        if (status) status.textContent = 'Loading more artwork…';

        try {
            const response = await fetch(link.href, {
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                signal,
            });

            if (!response.ok) throw new Error(`Artwork request failed with ${response.status}`);

            const nextDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextResults = nextDocument.querySelector('[data-gallery-results]');
            const nextItems = nextResults ? [...nextResults.querySelectorAll(':scope > .art-tile')] : [];

            if (!nextItems.length) throw new Error('The next artwork page did not contain any results.');

            const firstNewItem = nextItems[0];
            results.append(...nextItems);
            createIcons({ icons });

            const nextLink = nextDocument.querySelector('[data-gallery-load-more]');
            if (nextLink) {
                link.href = nextLink.getAttribute('href');
                link.removeAttribute('aria-busy');
                link.removeAttribute('aria-disabled');
            } else {
                link.remove();
            }

            const loaded = results.querySelectorAll(':scope > .art-tile').length;
            const total = Number(count?.dataset.galleryTotal || loaded);
            if (count) count.textContent = `${loaded} of ${total.toLocaleString()} ${total === 1 ? 'frame' : 'frames'} loaded`;
            if (status) status.textContent = nextLink
                ? `${nextItems.length} more artworks loaded.`
                : `All ${loaded} artworks are loaded.`;
            firstNewItem.querySelector('.art-tile-link')?.focus({ preventScroll: true });
        } catch (error) {
            if (error?.name === 'AbortError') return;
            link.removeAttribute('aria-busy');
            link.removeAttribute('aria-disabled');
            if (status) status.textContent = 'More artwork could not be loaded. Use the link to try again.';
        } finally {
            loading = false;
        }
    }, { signal });
}

function setupNavigation(signal) {
    const header = document.querySelector('.site-header');
    const toggle = document.querySelector('[data-nav-toggle]');
    const nav = document.querySelector('[data-nav]');

    const updateScroll = () => {
        header?.classList.toggle('scrolled', window.scrollY > 24);
        const documentHeight = document.documentElement.scrollHeight - window.innerHeight;
        const progress = documentHeight > 0 ? (window.scrollY / documentHeight) * 100 : 0;
        document.querySelector('[data-scroll-progress]')?.style.setProperty('width', `${progress}%`);
    };

    const setOpen = (open, restoreFocus = false) => {
        nav?.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
        if (restoreFocus) toggle.focus();
    };

    toggle?.addEventListener('click', () => setOpen(!nav?.classList.contains('open')), { signal });

    nav?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
        setOpen(false);
    }, { signal }));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && nav?.classList.contains('open')) setOpen(false, true);
    }, { signal });

    document.addEventListener('click', (event) => {
        if (!nav?.classList.contains('open') || header?.contains(event.target)) return;
        setOpen(false);
    }, { signal });

    window.addEventListener('scroll', updateScroll, { passive: true, signal });
    updateScroll();
}

function setupEnhancedNavigation(signal) {
    const navigate = (url) => {
        if (typeof window.Livewire?.navigate !== 'function') return false;
        window.Livewire.navigate(url);

        return true;
    };

    document.querySelectorAll('[data-navigate-pagination] a[href]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            if (navigate(link.href)) event.preventDefault();
        }, { signal });
    });

    document.querySelectorAll('form[data-navigate-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.method.toLowerCase() !== 'get') return;
            const url = new URL(form.action || window.location.href);
            url.search = new URLSearchParams(new FormData(form)).toString();
            if (navigate(url.toString())) event.preventDefault();
        }, { signal });
    });
}

function setupReveal(signal) {
    const elements = document.querySelectorAll('[data-reveal]');

    if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        elements.forEach((element) => element.classList.add('revealed'));
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('revealed');
            observer.unobserve(entry.target);
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

    elements.forEach((element) => observer.observe(element));
    signal.addEventListener('abort', () => observer.disconnect(), { once: true });
}

function centerCurrentItem(track, activeItem) {
    if (!track || !activeItem) return;

    requestAnimationFrame(() => {
        if (!track.isConnected || !activeItem.isConnected) return;

        const trackBounds = track.getBoundingClientRect();
        const activeBounds = activeItem.getBoundingClientRect();
        const activeCenter = (activeBounds.left - trackBounds.left) + track.scrollLeft + (activeBounds.width / 2);
        const centeredPosition = activeCenter - (track.clientWidth / 2);
        track.scrollLeft = Math.max(0, centeredPosition);
    });
}

function setupCollectionSwitcher() {
    const track = document.querySelector('[data-collection-switcher-track]');
    const activeItem = track?.querySelector('[data-collection-switcher-item][aria-current="page"]');

    centerCurrentItem(track, activeItem);
}

function setupTagFilters() {
    const track = document.querySelector('[data-tag-filter-strip]');
    const activeItem = track?.querySelector('.tag-filter[aria-current="page"]');

    centerCurrentItem(track, activeItem);
}

function setupHashTarget() {
    if (!window.location.hash) return;

    let targetId;

    try {
        targetId = decodeURIComponent(window.location.hash.slice(1));
    } catch {
        return;
    }

    const target = document.getElementById(targetId);

    if (!target) return;

    requestAnimationFrame(() => {
        if (!target.isConnected) return;

        target.scrollIntoView({ behavior: 'instant', block: 'start' });

        target.querySelector('[data-gallery-focus-target]')?.focus({ preventScroll: true });
    });
}

const historyFocusTargets = new Map();

function rememberNavigationFocus() {
    const focusedLink = document.activeElement instanceof Element
        ? document.activeElement.closest('a[href]')
        : null;

    if (!focusedLink) return;

    historyFocusTargets.set(window.location.href, {
        href: focusedLink.href,
        key: focusedLink.dataset.navigationFocusKey || null,
    });
}

function restoreHistoryFocus() {
    const source = historyFocusTargets.get(window.location.href);

    if (!source) return;

    const links = Array.from(document.querySelectorAll('a[href]'));
    const sourceLink = (source.key && links.find((link) => link.dataset.navigationFocusKey === source.key))
        || links.find((link) => link.href === source.href);

    requestAnimationFrame(() => sourceLink?.focus({ preventScroll: true }));
}

let player;
let pageController;
let restoringHistory = false;

function setupPage({ handleHash = true } = {}) {
    pageController?.abort();
    pageController = new AbortController();
    const { signal } = pageController;

    createIcons({ icons });
    setupNavigation(signal);
    setupEnhancedNavigation(signal);
    setupGalleryPagination(signal);
    setupReveal(signal);
    setupLightbox(signal);
    setupArtworkPreviewDeterrence(signal);
    setupArtworkNavigation(signal);
    setupCollectionSwitcher();
    setupTagFilters();

    if (player) {
        player.updateLibrary(window.creativeAi || {});
    } else {
        player = new CreativePlayer(window.creativeAi || {});
    }

    player.bindPageControls(signal);

    if (handleHash) setupHashTarget();
}

document.addEventListener('DOMContentLoaded', setupPage, { once: true });
document.addEventListener('livewire:navigate', (event) => {
    restoringHistory = Boolean(event.detail?.history);

    if (!restoringHistory) rememberNavigationFocus();
});
document.addEventListener('livewire:navigating', () => pageController?.abort());
document.addEventListener('livewire:navigated', () => {
    const shouldFocusArtworkViewer = artworkNavigationPending && !restoringHistory;

    artworkNavigationPending = false;
    setupPage({ handleHash: !restoringHistory });

    if (restoringHistory) {
        restoreHistoryFocus();
    } else if (shouldFocusArtworkViewer) {
        focusArtworkViewer();
    }

    restoringHistory = false;
});
