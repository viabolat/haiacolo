document.addEventListener("DOMContentLoaded", () => {
    const merchantList = document.getElementById("merchant-list");
    const loadingState = document.getElementById("loading-state");

    /**
     * Creates an HTML card for a single merchant.
     * @param {object} merchant - The merchant data from the API.
     * @returns {string} - The HTML string for the merchant card.
     */
    function createMerchantCard(merchant) {
        const isFoundingMember = merchant.is_founding_member;
        const location = merchant.location || 'N/A';
        const offer = merchant.offer_description || 'Ofertă specială în curând!';

        return `
            <div class="bg-white rounded-lg shadow-md overflow-hidden transform hover:-translate-y-1 transition-transform duration-300 border ${isFoundingMember ? 'border-primary-500' : 'border-gray-200'}">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xl font-bold text-gray-800">${merchant.name}</h3>
                        ${isFoundingMember ? '<span class="bg-yellow-200 text-yellow-800 text-xs font-semibold px-2.5 py-0.5 rounded-full">Membru Fondator</span>' : ''}
                    </div>
                    <p class="text-gray-600 mb-2 flex items-center">
                        <i class="fas fa-tag fa-fw mr-2 text-secondary-500"></i>
                        <span>${merchant.business_type || 'Afacere Locală'}</span>
                    </p>
                    <p class="text-gray-600 mb-4 flex items-center">
                        <i class="fas fa-map-marker-alt fa-fw mr-2 text-secondary-500"></i>
                        <span>Zona <strong>${location}</strong></span>
                    </p>
                    <div class="bg-gray-100 p-3 rounded-md">
                        <p class="text-sm font-semibold text-gray-700">Ofertă curentă sau viitoare:</p>
                        <p class="text-secondary-700 font-medium">${offer}</p>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Fetches merchants from the API and renders them on the page.
     */
    async function fetchAndRenderMerchants() {
        try {
            const response = await fetch('/api.php?route=/api/merchants');
            if (!response.ok) {
                throw new Error(`API request failed with status ${response.status}`);
            }
            const merchants = await response.json();

            loadingState.style.display = 'none';

            if (merchants && merchants.length > 0) {
                merchantList.innerHTML = merchants.map(createMerchantCard).join('');
            } else {
                merchantList.innerHTML = `
                    <div class="md:col-span-2 lg:col-span-3 text-center py-10">
                        <p class="text-lg text-gray-600">Nu există parteneri activi în acest moment. Reveniți în curând!</p>
                    </div>
                `;
            }

        } catch (error) {
            console.error("Failed to fetch merchants:", error);
            loadingState.style.display = 'none';
            merchantList.innerHTML = `
                <div class="md:col-span-2 lg:col-span-3 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md">
                    <p class="font-bold">Eroare la încărcarea datelor</p>
                    <p>Nu am putut prelua lista de parteneri. Vă rugăm să încercați din nou mai târziu.</p>
                </div>
            `;
        }
    }

    // Initial fetch
    fetchAndRenderMerchants();
});