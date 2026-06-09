// ========== UNIVERSAL HAMBURGER MENU ==========
document.addEventListener('DOMContentLoaded', function() {
    const hamburgerBtn = document.getElementById('hamburgerBtn');
    const navLinks = document.querySelector('.nav-links');
    const navOverlay = document.getElementById('navOverlay');
    
    if (hamburgerBtn && navLinks) {
        hamburgerBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            navLinks.classList.toggle('show');
            if (navOverlay) navOverlay.classList.toggle('show');
        });
    }
    
    if (navOverlay) {
        navOverlay.addEventListener('click', function() {
            navLinks.classList.remove('show');
            navOverlay.classList.remove('show');
        });
    }
    
    // Close menu when clicking a link
    if (navLinks) {
        navLinks.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', function() {
                navLinks.classList.remove('show');
                if (navOverlay) navOverlay.classList.remove('show');
            });
        });
    }
});