document.addEventListener('DOMContentLoaded', function() {
    fetch('/includes/header.html')
        .then(response => response.text())
        .then(data => {
            const headerPlaceholder = document.getElementById('header-placeholder');
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = data;
            const headerContent = Array.from(tempDiv.children);
            headerPlaceholder.replaceWith(...headerContent);

            // Mobile menu logic
            const mobileMenuButton = document.getElementById("mobile-menu-button");
            const closeMobileMenu = document.getElementById("close-mobile-menu");
            const mobileMenu = document.getElementById("mobile-menu");
            const body = document.body;

            if (mobileMenuButton) {
                mobileMenuButton.addEventListener("click", function () {
                    if (mobileMenu) {
                        mobileMenu.classList.remove("hidden");
                        body.style.overflow = "hidden"; // Prevent scrolling behind overlay
                    }
                });
            }

            if (closeMobileMenu) {
                closeMobileMenu.addEventListener("click", function () {
                    if (mobileMenu) {
                        mobileMenu.classList.add("hidden");
                        body.style.overflow = ""; // Restore scrolling
                    }
                });
            }

            // Smooth scroll for internal links and close menu
            if (mobileMenu) {
                mobileMenu.querySelectorAll("a").forEach(link => {
                    link.addEventListener("click", function (e) {
                        if (this.hash) {
                            const targetId = this.hash;
                            if (window.location.pathname === '/index.html' || window.location.pathname === '/') {
                                e.preventDefault();
                                const targetElement = document.querySelector(targetId);

                                if (targetElement) {
                                    targetElement.scrollIntoView({ behavior: 'smooth' });
                                }
                            } else {
                                window.location.href = '/index.html' + targetId;
                            }
                        }
                        mobileMenu.classList.add("hidden");
                        body.style.overflow = "";
                    });
                });
            }
        });

    fetch('/includes/footer.html')
        .then(response => response.text())
        .then(data => {
            document.getElementById('footer-placeholder').innerHTML = data;
        });
});
