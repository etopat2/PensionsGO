// menu_toggle.js
document.addEventListener("DOMContentLoaded", () => {
  const menuToggle = document.getElementById("menuToggle");
  const navLinks = document.getElementById("navLinks");
  const themeToggle = document.getElementById("themeToggle");
  const html = document.documentElement;

  // === Mobile menu toggle ===
  if (menuToggle && navLinks) {
    menuToggle.addEventListener("click", () => {
      navLinks.classList.toggle("show");
      menuToggle.classList.toggle("open");
    });
  }

  // === Theme toggle ===
  const storedTheme = localStorage.getItem("theme");
  if (storedTheme) html.setAttribute("data-theme", storedTheme);

  if (themeToggle) {
    themeToggle.addEventListener("click", () => {
      const current = html.getAttribute("data-theme");
      const newTheme = current === "light" ? "dark" : "light";
      html.setAttribute("data-theme", newTheme);
      localStorage.setItem("theme", newTheme);
    });
  }

  // === Hide menu when clicking outside (mobile) ===
  document.addEventListener("click", (e) => {
    if (!menuToggle || !navLinks) return;
    if (!menuToggle.contains(e.target) && !navLinks.contains(e.target)) {
      navLinks.classList.remove("show");
      menuToggle.classList.remove("open");
    }
  });
});
