const header = document.querySelector(".site-header");

window.addEventListener("scroll", () => {
  if (window.scrollY > 12) {
    header.style.boxShadow = "0 10px 28px rgba(24, 45, 66, 0.08)";
  } else {
    header.style.boxShadow = "none";
  }
});
