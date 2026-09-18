<?php /** @var array|null $currentUser */ ?>
<h1>Store</h1>
<p class="card__meta" style="max-width: var(--ac-measure);">
  Detergent, packaging and supplies for your household or laundry business — pay with M-Pesa and we deliver to your door.
</p>

<div class="store-layout">
  <section aria-labelledby="catalog-heading">
    <h2 id="catalog-heading" class="visually-hidden">Products</h2>
    <div id="category-filters" class="chip-row" role="group" aria-label="Filter by category"></div>
    <p id="catalog-status" class="card__meta" role="status">Loading products…</p>
    <div id="product-grid" class="product-grid"></div>
  </section>

  <aside class="cart-panel card" aria-labelledby="cart-heading">
    <h2 id="cart-heading">Your cart</h2>
    <div id="cart-lines"></div>
    <p id="cart-total" class="cart-total"></p>

    <form id="checkout-form" hidden>
      <label>
        Delivery address
        <input type="text" name="delivery_address" required minlength="5" autocomplete="street-address" placeholder="e.g. Kilimani, Nairobi" class="field">
      </label>
      <label>
        M-Pesa phone number
        <input type="tel" name="phone" required autocomplete="tel" placeholder="07XXXXXXXX" value="<?= htmlspecialchars($currentUser['phone_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="field">
      </label>
      <button type="submit" class="btn btn--primary" id="checkout-btn">Place order &amp; pay with M-Pesa</button>
    </form>
    <p id="checkout-status" class="card__meta" role="status" style="margin-top: var(--ac-space-2);"></p>
    <?php if (empty($currentUser)): ?>
      <p class="card__meta"><a href="/login">Log in</a> or <a href="/signup">sign up</a> before checking out.</p>
    <?php endif; ?>
  </aside>
</div>

<script type="module">
  const CART_KEY = "laundry_store_cart";
  const CATEGORY_LABELS = {
    detergents: "Detergents",
    packaging: "Packaging",
    stain_removal: "Stain removal",
    equipment: "Equipment",
  };
  const kes = (n) => "KES " + Number(n).toLocaleString("en-KE", { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  const label = (category) => CATEGORY_LABELS[category] ?? category.replace(/_/g, " ").replace(/^./, (c) => c.toUpperCase());

  const grid = document.getElementById("product-grid");
  const filters = document.getElementById("category-filters");
  const catalogStatus = document.getElementById("catalog-status");
  const cartLines = document.getElementById("cart-lines");
  const cartTotal = document.getElementById("cart-total");
  const checkoutForm = document.getElementById("checkout-form");
  const checkoutBtn = document.getElementById("checkout-btn");
  const checkoutStatus = document.getElementById("checkout-status");

  let products = [];
  let activeCategory = null;
  let cart = loadCart(); // { [productId]: quantity }

  function loadCart() {
    try {
      const parsed = JSON.parse(localStorage.getItem(CART_KEY) || "{}");
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch {
      return {};
    }
  }
  function saveCart() {
    localStorage.setItem(CART_KEY, JSON.stringify(cart));
  }

  function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs)) {
      if (key === "class") node.className = value;
      else if (key.startsWith("on")) node.addEventListener(key.slice(2), value);
      else node.setAttribute(key, value);
    }
    for (const child of children) node.append(child);
    return node;
  }

  function setQuantity(productId, quantity) {
    const product = products.find((p) => p.id === productId);
    const max = product ? Math.min(product.stock_quantity, 50) : 50;
    const clamped = Math.max(0, Math.min(quantity, max));
    if (clamped === 0) delete cart[productId];
    else cart[productId] = clamped;
    saveCart();
    renderCart();
  }

  function renderFilters() {
    const categories = [...new Set(products.map((p) => p.category))];
    filters.replaceChildren(
      ...[null, ...categories].map((category) =>
        el(
          "button",
          {
            type: "button",
            class: "chip",
            "aria-pressed": String(category === activeCategory),
            onclick: () => {
              activeCategory = category;
              renderFilters();
              renderProducts();
            },
          },
          category === null ? "All" : label(category)
        )
      )
    );
  }

  function renderProducts() {
    const visible = products.filter((p) => activeCategory === null || p.category === activeCategory);
    grid.replaceChildren(
      ...visible.map((product) => {
        const image = product.images[0];
        const media = el("div", { class: "product-card__media" });
        if (image) {
          media.append(el("img", { src: image.replace("w=800", "w=480"), alt: product.name, loading: "lazy", width: "480", height: "480" }));
        }
        const action = product.in_stock
          ? el("button", { type: "button", class: "btn btn--primary", onclick: () => setQuantity(product.id, (cart[product.id] || 0) + 1) }, "Add to cart")
          : el("button", { type: "button", class: "btn btn--secondary", disabled: "" }, "Out of stock");
        return el(
          "article",
          { class: "card product-card" },
          media,
          el("div", { class: "card__meta" }, label(product.category)),
          el("h3", {}, product.name),
          el("p", { class: "product-card__desc" }, product.description || ""),
          el("p", { class: "product-card__price" }, kes(product.price)),
          action
        );
      })
    );
    catalogStatus.textContent = visible.length ? "" : "No products in this category yet.";
  }

  function renderCart() {
    // Drop lines for products that no longer exist / went out of stock.
    for (const id of Object.keys(cart)) {
      const product = products.find((p) => p.id === Number(id));
      if (products.length && (!product || !product.in_stock)) delete cart[id];
    }

    const lines = Object.entries(cart).map(([id, quantity]) => ({ product: products.find((p) => p.id === Number(id)), quantity }))
      .filter((line) => line.product);

    if (!lines.length) {
      cartLines.replaceChildren(el("p", { class: "card__meta" }, "Your cart is empty."));
      cartTotal.textContent = "";
      checkoutForm.hidden = true;
      return;
    }

    cartLines.replaceChildren(
      ...lines.map(({ product, quantity }) =>
        el(
          "div",
          { class: "cart-line" },
          el("span", { class: "cart-line__name" }, product.name),
          el(
            "span",
            { class: "cart-line__qty" },
            el("button", { type: "button", class: "qty-btn", "aria-label": `Remove one ${product.name}`, onclick: () => setQuantity(product.id, quantity - 1) }, "−"),
            el("span", { "aria-live": "polite" }, String(quantity)),
            el("button", { type: "button", class: "qty-btn", "aria-label": `Add one ${product.name}`, onclick: () => setQuantity(product.id, quantity + 1) }, "+")
          ),
          el("span", { class: "cart-line__price" }, kes(product.price * quantity))
        )
      )
    );
    const total = lines.reduce((sum, { product, quantity }) => sum + product.price * quantity, 0);
    cartTotal.textContent = "Total: " + kes(total);
    checkoutForm.hidden = false;
  }

  checkoutForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = new FormData(checkoutForm);
    const items = Object.entries(cart).map(([id, quantity]) => ({ product_id: Number(id), quantity }));
    checkoutBtn.disabled = true;
    checkoutStatus.textContent = "Placing your order…";

    try {
      const orderRes = await fetch("/api/v1/store/orders", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ items, delivery_address: form.get("delivery_address") }),
      });
      const order = await orderRes.json();
      if (orderRes.status === 401) {
        checkoutStatus.replaceChildren("Please ", el("a", { href: "/login" }, "log in"), " to place your order — your cart is saved.");
        return;
      }
      if (!orderRes.ok) {
        checkoutStatus.textContent = order.error || "Could not place your order.";
        await loadProducts(); // stock may have changed
        return;
      }

      cart = {};
      saveCart();

      // The order exists now; whether or not the M-Pesa prompt goes out, the
      // order page is where the customer pays/retries or cancels.
      checkoutStatus.textContent = "Order placed — sending the M-Pesa prompt…";
      const phone = form.get("phone");
      let query = "";
      try {
        const payRes = await fetch("/api/v1/payments/mpesa/stk-push", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ store_order_id: order.id, phone }),
        });
        if (!payRes.ok) {
          const pay = await payRes.json();
          query = "?pay_error=" + encodeURIComponent(pay.error || "Could not start payment.");
        }
      } catch (e) {
        query = "?pay_error=" + encodeURIComponent("Network error: " + e.message);
      }
      window.location.href = `/store/orders/${order.id}${query}`;
    } catch (e) {
      checkoutStatus.textContent = "Network error: " + e.message;
    } finally {
      checkoutBtn.disabled = false;
    }
  });

  async function loadProducts() {
    try {
      const res = await fetch("/api/v1/store/products");
      if (!res.ok) throw new Error((await res.json()).error || res.statusText);
      products = await res.json();
      catalogStatus.textContent = "";
      renderFilters();
      renderProducts();
      renderCart();
    } catch (e) {
      catalogStatus.textContent = "Couldn't load products: " + e.message;
    }
  }

  renderCart();
  loadProducts();
</script>
