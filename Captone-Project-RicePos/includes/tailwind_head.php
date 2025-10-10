<?php
// Tailwind + Fonts + Compat include (no logic; UI-only)
?>
<!-- Fonts: Inter -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<!-- Tailwind CDN -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    darkMode: 'class',
    theme: {
      extend: {
        fontFamily: {
          sans: ['Inter', 'ui-sans-serif', 'system-ui', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'Noto Sans', 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol']
        },
        colors: {
          brand: {
            DEFAULT: '#2d6cdf',
            600: '#1e4fa3'
          }
        },
        boxShadow: {
          card: '0 8px 24px rgba(17,24,39,0.06)'
        },
        borderRadius: {
          xl: '14px'
        }
      }
    }
  };
  // Respect saved theme early to avoid FOUC
  (function(){
    try{
      var t = localStorage.getItem('theme');
      if (t === 'dark') { document.documentElement.classList.add('dark'); }
    }catch(e){}
  })();
</script>

<!-- Minimal global base + Tailwind compatibility helpers (keeps existing markup working) -->
<link rel="stylesheet" href="assets/css/tw-compat.css?v=1">

<style>
  html, body { height: 100%; }
  body { font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, Arial, Noto Sans, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol'; }
  /* Dark mode surface defaults */
  .dark body { background-color: #0b1220; color: #e5e7eb; }
</style>

<!-- Global UI helpers (SweetAlert2 helpers, theme toggle) -->
<script src="assets/js/ui.js"></script>


