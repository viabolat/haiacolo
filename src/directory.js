const merchantList = document.getElementById("merchant-list");
const loadingState = document.getElementById("loading-state");

async function fetchMerchants() {
  loadingState.style.display = "block"; // Show loading message
  merchantList.innerHTML = ""; // Clear previous content

  try {
    const response = await fetch("/api.php?route=/api/merchants");
    const { data: merchants, error } = await response.json();

    loadingState.style.display = "none"; // Hide loading message

    if (error) {
      console.error("Error fetching merchants:", error);
      merchantList.innerHTML =
        '<p class="text-red-500">A apărut o eroare la încărcarea afacerilor. Te rugăm să încerci din nou mai târziu.</p>';
      return;
    }

    if (merchants.length === 0) {
      merchantList.innerHTML =
        "<p>Momentan nu sunt afaceri active înscrise.</p>";
      return;
    }

    merchants.forEach((merchant) => {
      const card = document.createElement("div");
      card.className =
        "relative bg-white rounded-lg shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-300 ease-in-out";

      if (merchant.is_founding_member) {
        const badge = document.createElement('div');
        badge.className = 'absolute top-2 right-2 bg-yellow-400 text-yellow-900 px-2 py-1 text-xs font-bold rounded-full flex items-center space-x-1';
        badge.innerHTML = '<i class="fas fa-star text-yellow-700"></i> <span>Membru Fondator</span>';
        card.appendChild(badge);
      }

      const contentDiv = document.createElement('div');
      contentDiv.className = "pt-10 px-6 pb-6";

      const nameH3 = document.createElement('h3');
      nameH3.className = "font-bold text-2xl mb-2 text-primary-700";
      nameH3.textContent = merchant.name;

      const businessTypeDiv = document.createElement('div');
      businessTypeDiv.className = "text-md text-gray-700 mb-2";
      const businessTypeSpan = document.createElement('span');
      businessTypeSpan.className = 'font-semibold';
      businessTypeSpan.textContent = 'Tip: ';
      businessTypeDiv.appendChild(businessTypeSpan);
      businessTypeDiv.appendChild(document.createTextNode(merchant.business_type));

      const locationDiv = document.createElement('div');
      locationDiv.className = "text-md text-gray-700 mb-4";
      const locationSpan = document.createElement('span');
      locationSpan.className = 'font-semibold';
      locationSpan.textContent = 'Locație: ';
      locationDiv.appendChild(locationSpan);
      locationDiv.appendChild(document.createTextNode(merchant.location));

      const offerDiv = document.createElement('div');
      offerDiv.className = "bg-primary-50 p-4 rounded-lg mt-4 border-l-4 border-primary-500";
      const offerTitleP = document.createElement('p');
      offerTitleP.className = "font-semibold text-primary-800";
      offerTitleP.textContent = 'Ofertă Specială:';
      offerDiv.appendChild(offerTitleP);
      const offerP = document.createElement('p');
      offerP.className = "text-primary-900";
      offerP.textContent = merchant.offer_description;
      offerDiv.appendChild(offerP);

      contentDiv.appendChild(nameH3);
      contentDiv.appendChild(businessTypeDiv);
      contentDiv.appendChild(locationDiv);
      contentDiv.appendChild(offerDiv);

      card.appendChild(contentDiv);
      merchantList.appendChild(card);
    });
  } catch (err) {
    console.error("Network or parsing error:", err);
    loadingState.style.display = "none";
    merchantList.innerHTML =
      '<p class="text-red-500">A apărut o eroare de rețea. Te rugăm să verifici conexiunea și să încerci din nou.</p>';
  }
}

document.addEventListener("DOMContentLoaded", fetchMerchants);
