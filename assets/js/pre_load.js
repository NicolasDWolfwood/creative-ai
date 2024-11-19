document.addEventListener("DOMContentLoaded", function () {
  function disableRightClick(e) {
    e.preventDefault();
    return false;
  }

  function handlePoptroxPopup() {
    const popups = document.querySelectorAll(
      ".poptrox-popup, .poptrox-popup img, .poptrox-popup .pic"
    );
    popups.forEach((popup) => {
      popup.addEventListener("contextmenu", disableRightClick);
      popup.addEventListener("mousedown", disableRightClick);
    });
  }

  const mainImages = document.querySelectorAll(
    "#main img, #main .thumb, #main .image"
  );
  mainImages.forEach((img) => {
    img.addEventListener("contextmenu", disableRightClick);
    img.addEventListener("mousedown", disableRightClick);
  });

  const observer = new MutationObserver(function (mutations) {
    mutations.forEach(function (mutation) {
      if (mutation.addedNodes.length) {
        handlePoptroxPopup();
      }
    });
  });

  observer.observe(document.body, { childList: true, subtree: true });

  document.addEventListener("keydown", function (e) {
    if (e.ctrlKey && e.key === "s") {
      e.preventDefault();
      return false;
    }
    if ((e.ctrlKey && e.shiftKey && e.key === "i") || e.key === "F12") {
      e.preventDefault();
      return false;
    }
  });

  document.addEventListener("dragstart", function (e) {
    if (e.target.tagName === "IMG") {
      e.preventDefault();
      return false;
    }
  });
});
