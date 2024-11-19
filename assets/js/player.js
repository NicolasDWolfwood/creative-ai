class MusicPlayer {
  constructor() {
    this.initializeElements();
    this.initializeAudio();
    this.attachEventListeners();
    this.loadSettings();
    this.setupDragAndDrop();
    this.setupVisualizer();
  }

  initializeElements() {
    this.elements = {
      player: document.querySelector(".floating-player"),
      minimizeBtn: document.querySelector(".minimize-btn"),
      playerContent: document.querySelector(".player-content"),
      songSelect: document.getElementById("song-select"),
      playPauseBtn: document.getElementById("playPauseBtn"),
      prevBtn: document.getElementById("prevBtn"),
      nextBtn: document.getElementById("nextBtn"),
      seekBar: document.getElementById("seekBar"),
      volumeBar: document.getElementById("volumeBar"),
      currentSongDisplay: document.getElementById("currentSong"),
      currentTime: document.getElementById("currentTime"),
      duration: document.getElementById("duration"),
      volumeText: document.getElementById("volumeText"),
      repeatBtn: document.createElement("button"),
      shuffleBtn: document.createElement("button"),
    };

    // Prepare repeat and shuffle buttons
    this.setupExtraControls();
  }

  initializeAudio() {
    this.audio = new Audio();
    this.isPlaying = false;
    this.isRepeat = false;
    this.isShuffle = false;
    this.playHistory = [];
  }

  setupExtraControls() {
    // Shuffle button
    this.elements.shuffleBtn.className = "player-btn shuffle-btn";
    this.elements.shuffleBtn.innerHTML = '<i class="fas fa-random"></i>';

    // Repeat button
    this.elements.repeatBtn.className = "player-btn repeat-btn";
    this.elements.repeatBtn.innerHTML = '<i class="fas fa-redo"></i>';

    // Add to controls
    const controls = document.querySelector(".controls");
    controls.appendChild(this.elements.shuffleBtn);
    controls.appendChild(this.elements.repeatBtn);
  }

  attachEventListeners() {
    // Playback controls
    this.elements.playPauseBtn.addEventListener("click", () =>
      this.togglePlay()
    );
    this.elements.prevBtn.addEventListener("click", () => this.playPrevious());
    this.elements.nextBtn.addEventListener("click", () => this.playNext());
    this.elements.songSelect.addEventListener("change", (e) =>
      this.handleSongSelect(e)
    );
    this.elements.seekBar.addEventListener("input", (e) => this.handleSeek(e));
    this.elements.volumeBar.addEventListener("input", (e) =>
      this.handleVolume(e)
    );
    this.elements.minimizeBtn.addEventListener("click", () =>
      this.toggleMinimize()
    );

    // Shuffle and Repeat buttons
    this.elements.shuffleBtn.addEventListener("click", () =>
      this.toggleShuffle()
    );
    this.elements.repeatBtn.addEventListener("click", () =>
      this.toggleRepeat()
    );

    // Audio events
    this.audio.addEventListener("timeupdate", () => this.updateProgress());
    this.audio.addEventListener("ended", () => this.handleSongEnd());
    this.audio.addEventListener("loadedmetadata", () => this.updateDuration());

    // Keyboard shortcuts
    document.addEventListener("keydown", (e) =>
      this.handleKeyboardShortcuts(e)
    );
  }

  setupDragAndDrop() {
    let pos = { x: 0, y: 0 };

    const dragStart = (e) => {
      pos = {
        x: e.clientX - this.elements.player.offsetLeft,
        y: e.clientY - this.elements.player.offsetTop,
      };
      document.addEventListener("mousemove", drag);
      document.addEventListener("mouseup", dragEnd);
    };

    const drag = (e) => {
      this.elements.player.style.left = e.clientX - pos.x + "px";
      this.elements.player.style.top = e.clientY - pos.y + "px";
    };

    const dragEnd = () => {
      document.removeEventListener("mousemove", drag);
      document.removeEventListener("mouseup", dragEnd);
    };

    // Assuming there's an element with class 'player-handle' for dragging
    // If not, you can add it to your HTML structure
    const playerHandle = this.elements.player.querySelector(".player-handle");
    if (playerHandle) {
      playerHandle.addEventListener("mousedown", dragStart);
    }
  }

  setupVisualizer() {
    // Create audio context and analyzer
    this.audioContext = new (window.AudioContext ||
      window.webkitAudioContext)();
    this.analyzer = this.audioContext.createAnalyser();
    this.analyzer.fftSize = 256;

    // Connect audio to analyzer
    const source = this.audioContext.createMediaElementSource(this.audio);
    source.connect(this.analyzer);
    this.analyzer.connect(this.audioContext.destination);

    // Create visualization canvas
    this.setupVisualizerCanvas();
    this.drawVisualizer();
  }

  setupVisualizerCanvas() {
    const visualizer = document.createElement("canvas");
    visualizer.className = "visualizer";
    this.elements.playerContent.insertBefore(
      visualizer,
      this.elements.controls
    );
    this.canvas = visualizer;
    this.canvasCtx = visualizer.getContext("2d");

    // Adjust canvas size
    this.resizeCanvas();
    window.addEventListener("resize", () => this.resizeCanvas());
  }

  resizeCanvas() {
    this.canvas.width = this.elements.playerContent.clientWidth;
    this.canvas.height = 100; // Set desired height
  }

  drawVisualizer() {
    const bufferLength = this.analyzer.frequencyBinCount;
    const dataArray = new Uint8Array(bufferLength);

    const draw = () => {
      requestAnimationFrame(draw);
      this.analyzer.getByteFrequencyData(dataArray);

      this.canvasCtx.fillStyle = "rgba(20, 22, 25, 0.2)";
      this.canvasCtx.fillRect(0, 0, this.canvas.width, this.canvas.height);

      const barWidth = (this.canvas.width / bufferLength) * 2.5;
      let barHeight;
      let x = 0;

      for (let i = 0; i < bufferLength; i++) {
        barHeight = dataArray[i] / 2;

        const gradient = this.canvasCtx.createLinearGradient(
          0,
          0,
          0,
          this.canvas.height
        );
        gradient.addColorStop(0, "#afafaf");
        gradient.addColorStop(1, "#4b4b4b");

        this.canvasCtx.fillStyle = gradient;
        this.canvasCtx.fillRect(
          x,
          this.canvas.height - barHeight,
          barWidth,
          barHeight
        );

        x += barWidth + 1;
      }
    };

    draw();
  }

  togglePlay() {
    if (!this.audio.src) {
      this.showNotification("Please select a song first");
      return;
    }

    if (this.isPlaying) {
      this.audio.pause();
    } else {
      // Resume audio context if suspended (required for some browsers)
      if (this.audioContext.state === "suspended") {
        this.audioContext.resume();
      }
      this.audio.play().catch((e) => this.handlePlayError(e));
    }

    this.isPlaying = !this.isPlaying;
    this.updatePlayPauseIcon();
  }

  handlePlayError(error) {
    console.error("Playback error:", error);
    this.showNotification("Error playing audio. Please try again.");
    this.isPlaying = false;
    this.updatePlayPauseIcon();
  }

  showNotification(message) {
    if (!this.notificationElement) {
      this.notificationElement = document.createElement("div");
      this.notificationElement.className = "player-notification";
      this.elements.player.appendChild(this.notificationElement);
    }
    this.notificationElement.textContent = message;
    this.notificationElement.classList.add("show");
    setTimeout(() => {
      this.notificationElement.classList.remove("show");
    }, 3000);
  }

  updatePlayPauseIcon() {
    const icon = this.elements.playPauseBtn.querySelector("i");
    icon.className = this.isPlaying ? "fas fa-pause" : "fas fa-play";
  }

  playNext() {
    if (this.isShuffle) {
      this.playRandomSong();
    } else {
      const options = this.elements.songSelect.options;
      const currentIndex = this.elements.songSelect.selectedIndex;
      const nextIndex = (currentIndex + 1) % options.length;
      this.playSelectedIndex(nextIndex !== 0 ? nextIndex : 1);
    }
  }

  playPrevious() {
    const options = this.elements.songSelect.options;
    const currentIndex = this.elements.songSelect.selectedIndex;

    if (this.audio.currentTime > 3) {
      this.audio.currentTime = 0;
    } else {
      const prevIndex =
        currentIndex - 1 <= 0 ? options.length - 1 : currentIndex - 1;
      this.playSelectedIndex(prevIndex);
    }
  }

  playRandomSong() {
    const options = this.elements.songSelect.options;
    const currentIndex = this.elements.songSelect.selectedIndex;
    let randomIndex;

    do {
      randomIndex = Math.floor(Math.random() * (options.length - 1)) + 1;
    } while (randomIndex === currentIndex && options.length > 2);

    this.playSelectedIndex(randomIndex);
  }

  playSelectedIndex(index) {
    this.elements.songSelect.selectedIndex = index;
    const event = new Event("change");
    this.elements.songSelect.dispatchEvent(event);
  }

  handleSongSelect(e) {
    if (e.target.value) {
      this.loadAndPlaySong(
        e.target.value,
        e.target.options[e.target.selectedIndex].text
      );
    }
    this.updateButtonStates();
  }

  async loadAndPlaySong(src, title) {
    this.audio.src = src;
    this.audio.load();
    this.elements.currentSongDisplay.textContent = title;

    try {
      // Wait for the audio to be ready
      await this.audio.play();
      this.isPlaying = true;
      this.updatePlayPauseIcon();
      this.saveToLocalStorage("lastPlayedSong", src);
      this.saveToLocalStorage("lastPlayedSongText", title);
    } catch (e) {
      // Handle error if the play promise is rejected
      this.handlePlayError(e);
    }
  }

  handleSongEnd() {
    if (this.isRepeat) {
      this.audio.currentTime = 0;
      this.audio.play();
    } else {
      this.playNext();
    }
  }

  toggleShuffle() {
    this.isShuffle = !this.isShuffle;
    this.elements.shuffleBtn.classList.toggle("active");
    this.showNotification(`Shuffle ${this.isShuffle ? "enabled" : "disabled"}`);
  }

  toggleRepeat() {
    this.isRepeat = !this.isRepeat;
    this.elements.repeatBtn.classList.toggle("active");
    this.showNotification(`Repeat ${this.isRepeat ? "enabled" : "disabled"}`);
  }

  handleVolume(e) {
    this.audio.volume = e.target.value;
    this.updateVolumeDisplay();
    this.saveToLocalStorage("audioPlayerVolume", this.audio.volume);
  }

  updateVolumeDisplay() {
    const volumePercent = Math.round(this.audio.volume * 100);
    this.elements.volumeText.textContent = `${volumePercent}%`;
    const volumeIcon = document.querySelector(".volume-control i");

    if (this.audio.volume === 0) {
      volumeIcon.className = "fas fa-volume-mute";
    } else if (this.audio.volume < 0.5) {
      volumeIcon.className = "fas fa-volume-down";
    } else {
      volumeIcon.className = "fas fa-volume-up";
    }
  }

  handleSeek(e) {
    const time = (e.target.value * this.audio.duration) / 100;
    this.audio.currentTime = time;
  }

  updateProgress() {
    if (this.audio.duration) {
      this.elements.currentTime.textContent = this.formatTime(
        this.audio.currentTime
      );
      this.elements.seekBar.value =
        (this.audio.currentTime / this.audio.duration) * 100;

      const percent = (this.audio.currentTime / this.audio.duration) * 100;
      this.elements.seekBar.style.setProperty(
        "--seek-before-width",
        `${percent}%`
      );
    }
  }

  updateDuration() {
    this.elements.duration.textContent = this.formatTime(this.audio.duration);
  }

  formatTime(seconds) {
    const minutes = Math.floor(seconds / 60);
    seconds = Math.floor(seconds % 60);
    return `${minutes}:${seconds.toString().padStart(2, "0")}`;
  }

  increaseVolume() {
    let newVolume = Math.min(this.audio.volume + 0.1, 1);
    this.audio.volume = newVolume;
    this.elements.volumeBar.value = newVolume;
    this.updateVolumeDisplay();
    this.saveToLocalStorage("audioPlayerVolume", this.audio.volume);
  }

  decreaseVolume() {
    let newVolume = Math.max(this.audio.volume - 0.1, 0);
    this.audio.volume = newVolume;
    this.elements.volumeBar.value = newVolume;
    this.updateVolumeDisplay();
    this.saveToLocalStorage("audioPlayerVolume", this.audio.volume);
  }

  toggleMute() {
    this.audio.muted = !this.audio.muted;
    const volumeIcon = document.querySelector(".volume-control i");
    volumeIcon.className = this.audio.muted
      ? "fas fa-volume-mute"
      : this.audio.volume < 0.5
      ? "fas fa-volume-down"
      : "fas fa-volume-up";
  }

  saveToLocalStorage(key, value) {
    try {
      localStorage.setItem(key, value);
    } catch (e) {
      console.warn("LocalStorage write failed:", e);
    }
  }

  loadSettings() {
    const savedVolume = localStorage.getItem("audioPlayerVolume");
    if (savedVolume !== null) {
      this.audio.volume = parseFloat(savedVolume);
      this.elements.volumeBar.value = this.audio.volume;
      this.updateVolumeDisplay();
    }

    const lastPlayed = localStorage.getItem("lastPlayedSong");
    const lastPlayedText = localStorage.getItem("lastPlayedSongText");
    if (lastPlayed) {
      this.elements.songSelect.value = lastPlayed;
      this.elements.currentSongDisplay.textContent =
        lastPlayedText || "Select a song";
      this.audio.src = lastPlayed;
    }

    this.updateButtonStates();
  }

  updateButtonStates() {
    const hasSelection = this.elements.songSelect.selectedIndex > 0;
    this.elements.prevBtn.disabled = !hasSelection;
    this.elements.nextBtn.disabled = !hasSelection;
    this.elements.playPauseBtn.disabled = !hasSelection;
  }

  toggleMinimize() {
    this.elements.player.classList.toggle("minimized");
  }

  handleKeyboardShortcuts(e) {
    if (e.target.tagName === "INPUT") return;

    const shortcuts = {
      Space: () => this.togglePlay(),
      ArrowLeft: () => this.playPrevious(),
      ArrowRight: () => this.playNext(),
      ArrowUp: () => this.increaseVolume(),
      ArrowDown: () => this.decreaseVolume(),
      KeyM: () => this.toggleMute(),
      KeyR: () => this.toggleRepeat(),
      KeyS: () => this.toggleShuffle(),
    };

    if (shortcuts[e.code]) {
      e.preventDefault();
      shortcuts[e.code]();
    }
  }
}

// Initialize player when DOM is loaded
document.addEventListener("DOMContentLoaded", () => {
  window.musicPlayer = new MusicPlayer();
});
