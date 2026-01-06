/*!
* Start Bootstrap - Creative v7.0.7 (https://startbootstrap.com/theme/creative)
* Copyright 2013-2023 Start Bootstrap
* Licensed under MIT (https://github.com/StartBootstrap/startbootstrap-creative/blob/master/LICENSE)
*/
//
// Scripts
// 

window.addEventListener('DOMContentLoaded', event => {

    // Navbar shrink function
    var navbarShrink = function () {
        const navbarCollapsible = document.body.querySelector('#mainNav');
        if (!navbarCollapsible) {
            return;
        }
        if (window.scrollY === 0) {
            navbarCollapsible.classList.remove('navbar-shrink')
        } else {
            navbarCollapsible.classList.add('navbar-shrink')
        }

    };

    // Shrink the navbar 
    navbarShrink();

    // Shrink the navbar when page is scrolled
    document.addEventListener('scroll', navbarShrink);

    // Activate Bootstrap scrollspy on the main nav element
    const mainNav = document.body.querySelector('#mainNav');
    if (mainNav) {
        new bootstrap.ScrollSpy(document.body, {
            target: '#mainNav',
            rootMargin: '0px 0px -40%',
        });
    };

    // Collapse responsive navbar when toggler is visible
    const navbarToggler = document.body.querySelector('.navbar-toggler');
    const responsiveNavItems = [].slice.call(
        document.querySelectorAll('#navbarResponsive .nav-link')
    );
    responsiveNavItems.map(function (responsiveNavItem) {
        responsiveNavItem.addEventListener('click', () => {
            if (window.getComputedStyle(navbarToggler).display !== 'none') {
                navbarToggler.click();
            }
        });
    });

    // Activate SimpleLightbox plugin for portfolio items
    // Only apply to links that point to image files (not PHP pages)
    // This allows facility detail links to work normally
    const portfolioImageLinks = document.querySelectorAll('#portfolio a.portfolio-box[href$=".jpg"], #portfolio a.portfolio-box[href$=".jpeg"], #portfolio a.portfolio-box[href$=".png"], #portfolio a.portfolio-box[href$=".gif"], #portfolio a.portfolio-box[href$=".webp"]');
    if (portfolioImageLinks.length > 0) {
        new SimpleLightbox({
            elements: portfolioImageLinks
        });
    }
    
    // Ensure facility detail links (PHP pages) work normally by preventing lightbox interference
    const portfolioDetailLinks = document.querySelectorAll('#portfolio a.portfolio-box[href*=".php"]');
    portfolioDetailLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            // Allow normal navigation - don't let SimpleLightbox interfere
            // The href will naturally navigate to the PHP page
        });
    });

});
