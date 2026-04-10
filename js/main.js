// MKFB filmdb – main.js

// 1. Smooth scroll für Anchor-Links (Offset für fixed Navbar)
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const href = a.getAttribute('href');
        if (href === '#') return;
        const target = document.querySelector(href);
        if (target) {
            e.preventDefault();
            const offset = 66; // --nav-height
            const top = target.getBoundingClientRect().top + window.scrollY - offset;
            window.scrollTo({ top, behavior: 'smooth' });
        }
    });
});

// 2. Auto-dismiss alerts nach 4 Sekunden
document.querySelectorAll('.alert-dismissible').forEach(alert => {
    setTimeout(() => {
        const btn = alert.querySelector('.btn-close');
        if (btn) btn.click();
    }, 4000);
});

// 3. Navbar-Shadow beim Scrollen
const nav = document.getElementById('mainNav');
if (nav) {
    window.addEventListener('scroll', () => {
        nav.classList.toggle('scrolled', window.scrollY > 20);
    }, { passive: true });
}

// 4. Aktiver Anchor-Link per IntersectionObserver
const sections = document.querySelectorAll('section[id]');
const navLinks = document.querySelectorAll('.mkfb-nav a[href^="#"]');

if (sections.length && navLinks.length) {
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                navLinks.forEach(link => {
                    link.classList.remove('nav-section-active');
                    if (link.getAttribute('href') === '#' + entry.target.id) {
                        link.classList.add('nav-section-active');
                    }
                });
            }
        });
    }, { rootMargin: '-50% 0px -50% 0px' });

    sections.forEach(s => observer.observe(s));
}
