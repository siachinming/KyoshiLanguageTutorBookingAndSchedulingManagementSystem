// ============================================
// ADMIN.JS - SIMPLE WORKING VERSION
// ============================================

// Single toggle function for both mobile and desktop (NO event parameter)
function toggleDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    if (!dropdown) return;
    
    if (dropdown.style.display === 'block') {
        dropdown.style.display = 'none';
        dropdown.classList.remove('show');
    } else {
        dropdown.style.display = 'block';
        dropdown.classList.add('show');
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const mobileProfileBtn = document.querySelector('.mobile-profile-btn');
    const desktopProfile = document.querySelector('.admin-profile');
    
    if (!dropdown) return;
    
    // Check if click is on profile button or inside dropdown
    const isClickOnMobileBtn = mobileProfileBtn && mobileProfileBtn.contains(e.target);
    const isClickOnDesktop = desktopProfile && desktopProfile.contains(e.target);
    const isClickInsideDropdown = dropdown.contains(e.target);
    
    // If click is outside all profile elements and outside dropdown, close it
    if (!isClickOnMobileBtn && !isClickOnDesktop && !isClickInsideDropdown) {
        dropdown.style.display = 'none';
        dropdown.classList.remove('show');
    }
});

// Prevent dropdown from closing when clicking inside it
const dropdownElement = document.getElementById('profileDropdown');
if (dropdownElement) {
    dropdownElement.addEventListener('click', function(e) {
        e.stopPropagation();
    });
}

// Close dropdown on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const dropdown = document.getElementById('profileDropdown');
        if (dropdown) {
            dropdown.style.display = 'none';
            dropdown.classList.remove('show');
        }
    }
});

// ============================================
// MOBILE SIDEBAR MENU TOGGLE
// ============================================
const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');

if (menuToggle) {
    menuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('open');
        if (overlay) overlay.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
        
        // Close dropdown when opening sidebar
        const dropdown = document.getElementById('profileDropdown');
        if (dropdown) {
            dropdown.style.display = 'none';
            dropdown.classList.remove('show');
        }
    });
}

if (overlay) {
    overlay.addEventListener('click', function() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    });
}

// Close sidebar on window resize
window.addEventListener('resize', function() {
    if (window.innerWidth > 900) {
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
});

// ============================================
// AUTO-DISMISS ALERTS
// ============================================
function setupAutoDismissAlerts() {
    const alerts = document.querySelectorAll('.alert-card');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.classList.add('fade-out');
            setTimeout(() => {
                if (alert.parentNode) alert.remove();
            }, 500);
        }, 5000);
    });
}

function addCloseButtonToAlerts() {
    const alerts = document.querySelectorAll('.alert-card');
    alerts.forEach(alert => {
        if (!alert.querySelector('.alert-close')) {
            const closeBtn = document.createElement('button');
            closeBtn.innerHTML = '&times;';
            closeBtn.className = 'alert-close';
            closeBtn.onclick = () => {
                alert.classList.add('fade-out');
                setTimeout(() => alert.remove(), 500);
            };
            alert.appendChild(closeBtn);
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    setupAutoDismissAlerts();
    addCloseButtonToAlerts();
});

// ============================================
// PREVENT BACK BUTTON FROM LOGGING OUT
// ============================================
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});