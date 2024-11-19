document.addEventListener("DOMContentLoaded", function () {
  fetch("images-metadata.json")
    .then((response) => response.json())
    .then((data) => {
      const main = document.getElementById("main");
      main.innerHTML = "";

      // Sort images by filename number, highest first
      data.images.sort((a, b) => {
        const numA = parseInt(a.filename.match(/\d+/)[0]);
        const numB = parseInt(b.filename.match(/\d+/)[0]);
        return numB - numA;
      });

      // Add images
      data.images.forEach((image) => {
        const article = document.createElement("article");
        article.className = "thumb";
        article.innerHTML = `
                          <a href="images/fulls/${image.filename}" class="image">
                              <img src="images/thumbs/${image.filename}" alt="" />
                          </a>
                          <h2>${image.title}</h2>
                          <p>${image.description}</p>
                      `;
        main.appendChild(article);
      });

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
