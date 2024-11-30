document.addEventListener("DOMContentLoaded", function () {
  fetch("images-metadata.json")
    .then((response) => response.json())
    .then((data) => {
      const main = document.getElementById("main");
      main.innerHTML = "";

      const maxImageNumber = data.maxImageNumber; // Get max number from JSON
      const description = data.description; // Get description from JSON

      // Loop backwards through numbers and create images
      for (let i = maxImageNumber; i >= 1; i--) {
        const filename = String(i).padStart(4, "0") + ".jpg"; // Format as 0001.jpg, 0002.jpg, etc.

        const article = document.createElement("article");
        article.className = "thumb";
        article.innerHTML = `
          <a href="images/fulls/${filename}" class="image">
              <img src="images/thumbs/${filename}" alt="" />
          </a>
          <h2>${i}</h2> <!-- Use i directly as the title -->
          <p>${description}</p>
        `;
        main.appendChild(article);
      }

      // Initialize poptrox
      if (window.$) {
        $("#main").poptrox({
          baseZIndex: 20000,
          caption: function ($a) {
            return $a.next("h2").text();
          },
          fadeSpeed: 300,
          onPopupClose: function () {
            document.body.classList.remove("is-covered");
          },
          onPopupOpen: function () {
            document.body.classList.add("is-covered");
          },
          overlayColor: "#000000",
          overlayOpacity: 0.75,
          popupCloserText: "",
          popupLoaderText: "",
          popupSpeed: 300,
          selector: ".thumb > a.image",
          useBodyOverflow: false,
          usePopupCaption: true,
          usePopupCloser: true,
          usePopupDefaultStyling: false,
          usePopupForceClose: true,
          usePopupLoader: true,
          usePopupNav: true,
          windowMargin: 50,
        });
      }
    })
    .catch((error) => console.error("Error:", error));
});
