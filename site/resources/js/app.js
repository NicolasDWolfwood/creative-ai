import { createIcons, icons } from 'lucide';

class CreativePlayer {
    constructor(payload) {
        this.playlists = payload.playlists || [];
        this.playlistIndex = 0;
        this.trackIndex = 0;
        this.isShuffle = false;
        this.isRepeat = false;
        this.audio = new Audio();
        this.audio.preload = 'metadata';
        this.elements = this.collectElements();
        this.restoreState();
        this.bind();
        this.renderPlaylists();
        this.loadTrack(false);
        this.drawIdleVisualizer();
    }

    collectElements() {
        return {
            player: document.querySelector('[data-player]'),
            playlistSelect: document.querySelector('[data-playlist-select]'),
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
        this.elements.collapses.forEach((button) => button.addEventListener('click', () => this.elements.player.classList.toggle('collapsed')));
        this.elements.playlistSelect?.addEventListener('change', () => {
            this.playlistIndex = Number(this.elements.playlistSelect.value);
            this.trackIndex = 0;
            this.loadTrack(true);
        });

        this.audio.addEventListener('timeupdate', () => this.updateProgress());
        this.audio.addEventListener('loadedmetadata', () => this.updateProgress());
        this.audio.addEventListener('ended', () => (this.isRepeat ? this.replay() : this.next(true)));

        document.querySelectorAll('[data-playlist-id]').forEach((button) => {
            button.addEventListener('click', () => this.playPlaylist(button.dataset.playlistId));
        });

        document.querySelector('[data-player-focus]')?.addEventListener('click', () => {
            this.elements.player?.classList.remove('collapsed');
            this.elements.play?.focus();
        });
    }

    restoreState() {
        const state = JSON.parse(localStorage.getItem('creative-ai-player') || '{}');
        this.playlistIndex = Number.isInteger(state.playlistIndex) ? state.playlistIndex : 0;
        this.trackIndex = Number.isInteger(state.trackIndex) ? state.trackIndex : 0;
        this.audio.volume = typeof state.volume === 'number' ? state.volume : 0.85;
        this.elements.volume.value = this.audio.volume;
        this.isShuffle = Boolean(state.isShuffle);
        this.isRepeat = Boolean(state.isRepeat);
        this.elements.shuffle?.classList.toggle('active', this.isShuffle);
        this.elements.repeat?.classList.toggle('active', this.isRepeat);
    }

    saveState() {
        localStorage.setItem('creative-ai-player', JSON.stringify({
            playlistIndex: this.playlistIndex,
            trackIndex: this.trackIndex,
            volume: this.audio.volume,
            isShuffle: this.isShuffle,
            isRepeat: this.isRepeat,
        }));
    }

    renderPlaylists() {
        if (!this.elements.playlistSelect) return;

        this.elements.playlistSelect.innerHTML = '';
        this.playlists.forEach((playlist, index) => {
            const option = document.createElement('option');
            option.value = index;
            option.textContent = playlist.title;
            this.elements.playlistSelect.append(option);
        });

        this.playlistIndex = Math.min(this.playlistIndex, Math.max(0, this.playlists.length - 1));
        this.elements.playlistSelect.value = this.playlistIndex;
        this.markActivePlaylist();
    }

    currentPlaylist() {
        return this.playlists[this.playlistIndex];
    }

    currentTrack() {
        return this.currentPlaylist()?.tracks?.[this.trackIndex];
    }

    loadTrack(autoplay) {
        const playlist = this.currentPlaylist();
        if (!playlist || playlist.tracks.length === 0) {
            this.elements.title.textContent = 'No tracks published';
            this.elements.artist.textContent = 'Add music in the admin panel';
            return;
        }

        this.trackIndex = Math.min(this.trackIndex, playlist.tracks.length - 1);
        const track = this.currentTrack();
        this.audio.src = track.url;
        this.elements.title.textContent = track.title;
        this.elements.artist.textContent = track.artist || playlist.title;
        this.elements.art.style.backgroundImage = track.cover ? `url("${track.cover}")` : '';
        this.elements.playlistSelect.value = this.playlistIndex;
        this.updatePlayIcon(false);
        this.markActivePlaylist();
        this.saveState();

        if (autoplay) {
            this.play();
        }
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
        this.saveState();
    }

    toggleRepeat() {
        this.isRepeat = !this.isRepeat;
        this.elements.repeat?.classList.toggle('active', this.isRepeat);
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
        createIcons({ icons });
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

function setupLightbox() {
    const panel = document.querySelector('[data-lightbox-panel]');
    if (!panel) return;

    const image = panel.querySelector('[data-lightbox-image]');
    const title = panel.querySelector('[data-lightbox-title]');
    const description = panel.querySelector('[data-lightbox-description]');

    const triggers = [...document.querySelectorAll('[data-lightbox]')];
    const items = [];
    const triggerIndexes = triggers.map((trigger) => {
        const existingIndex = items.findIndex((item) => item.dataset.full === trigger.dataset.full);

        if (existingIndex >= 0) return existingIndex;

        items.push(trigger);

        return items.length - 1;
    });
    let activeIndex = 0;

    const show = (index) => {
        if (!items.length) return;
        activeIndex = (index + items.length) % items.length;
        const button = items[activeIndex];
        image.src = button.dataset.full;
        image.alt = button.dataset.alt || button.dataset.title || '';
        title.textContent = button.dataset.title || '';
        description.textContent = button.dataset.description || '';
    };

    triggers.forEach((button, index) => {
        button.addEventListener('click', () => {
            show(triggerIndexes[index]);
            panel.classList.add('open');
            panel.setAttribute('aria-hidden', 'false');
            document.body.classList.add('lightbox-open');
        });
    });

    const close = () => {
        panel.classList.remove('open');
        panel.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('lightbox-open');
        image.src = '';
    };

    panel.querySelector('[data-lightbox-close]')?.addEventListener('click', close);
    panel.querySelector('[data-lightbox-prev]')?.addEventListener('click', () => show(activeIndex - 1));
    panel.querySelector('[data-lightbox-next]')?.addEventListener('click', () => show(activeIndex + 1));
    panel.addEventListener('click', (event) => {
        if (event.target === panel) close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') close();
        if (panel.classList.contains('open') && event.key === 'ArrowLeft') show(activeIndex - 1);
        if (panel.classList.contains('open') && event.key === 'ArrowRight') show(activeIndex + 1);
    });
}

function setupNavigation() {
    const header = document.querySelector('.site-header');
    const toggle = document.querySelector('[data-nav-toggle]');
    const nav = document.querySelector('[data-nav]');

    const updateScroll = () => {
        header?.classList.toggle('scrolled', window.scrollY > 24);
        const documentHeight = document.documentElement.scrollHeight - window.innerHeight;
        const progress = documentHeight > 0 ? (window.scrollY / documentHeight) * 100 : 0;
        document.querySelector('[data-scroll-progress]')?.style.setProperty('width', `${progress}%`);
    };

    toggle?.addEventListener('click', () => {
        const open = nav?.classList.toggle('open') || false;
        toggle.setAttribute('aria-expanded', String(open));
    });

    nav?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
        nav.classList.remove('open');
        toggle?.setAttribute('aria-expanded', 'false');
    }));

    window.addEventListener('scroll', updateScroll, { passive: true });
    updateScroll();
}

function setupReveal() {
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
}

document.addEventListener('DOMContentLoaded', () => {
    createIcons({ icons });
    setupNavigation();
    setupReveal();
    setupLightbox();
    new CreativePlayer(window.creativeAi || {});
});
