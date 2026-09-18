<?php
/** @var int $orderId */
/** @var array|null $currentUser */
?>
<h1>Store order #<?= $orderId ?></h1>

<?php if (empty($currentUser)): ?>
  <p class="card__meta"><a href="/login">Log in</a> to see your order.</p>
<?php else: ?>
  <div id="order-summary" style="max-width: 36rem;"><p class="card__meta" role="status">Loading order…</p></div>
  <div id="order-payment" style="max-width: 28rem; margin-top: var(--ac-space-6);"></div>

  <script type="module">
    import { createStatusBadge } from "/assets/js/components/status-badge.js";

    const orderId = <?= $orderId ?>;
    const initialPayError = new URLSearchParams(window.location.search).get("pay_error");
    const summary = document.getElementById("order-summary");
    const paymentSection = document.getElementById("order-payment");
    const kes = (n) => "KES " + Number(n).toLocaleString("en-KE", { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    const STATUS_LABEL = { pending: "Awaiting payment", paid: "Paid — being prepared", fulfilled: "Delivered", cancelled: "Cancelled" };
    const BADGE_TONE = { pending: "warning", paid: "accent", fulfilled: "success", cancelled: "danger" };
    const BADGE_ICON = { pending: "dot", paid: "check", fulfilled: "check", cancelled: "alert" };
    let polling = null;
    let payMessage = initialPayError;

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

    function renderSummary(order) {
      summary.replaceChildren(
        createStatusBadge({ label: STATUS_LABEL[order.status] || order.status, tone: BADGE_TONE[order.status] ?? "neutral", icon: BADGE_ICON[order.status] ?? "dot" }),
        el("p", { class: "card__meta", style: "margin-top: var(--ac-space-2);" }, "Deliver to: " + order.delivery_address),
        el(
          "ul",
          { class: "order-lines" },
          ...order.items.map((item) =>
            el("li", {}, el("span", {}, `${item.quantity} × ${item.name}`), el("span", {}, kes(item.unit_price * item.quantity)))
          )
        ),
        el("p", { class: "cart-total" }, "Total: " + kes(order.total_amount))
      );
    }

    function renderPayment(order) {
      const payment = order.payment;

      if (order.status === "paid" || order.status === "fulfilled") {
        paymentSection.replaceChildren(el("p", { class: "card__meta" }, `Paid via M-Pesa — ${kes(order.total_amount)}${payment?.external_reference ? " (ref " + payment.external_reference + ")" : ""}.`));
        return;
      }
      if (order.status === "cancelled") {
        paymentSection.replaceChildren(el("p", { class: "card__meta" }, "This order was cancelled. "), el("a", { href: "/store" }, "Back to the store"));
        return;
      }

      // Keep the form's typed values across 5s polling re-renders.
      if (paymentSection.querySelector("#order-pay-form")) return;

      const form = el(
        "form",
        { id: "order-pay-form", style: "display:flex; flex-direction:column; gap: var(--ac-space-2);" },
        el("label", {}, "M-Pesa phone number", el("input", { type: "tel", name: "phone", required: "", placeholder: "07XXXXXXXX", class: "field", autocomplete: "tel" })),
        el("button", { type: "submit", class: "btn btn--primary" }, `Pay ${kes(order.total_amount)} with M-Pesa`),
        el("button", { type: "button", class: "btn btn--secondary", id: "cancel-order-btn" }, "Cancel order"),
        el("p", { class: "card__meta", id: "order-pay-status", role: "status" }, payMessage || (payment?.status === "pending" ? "M-Pesa prompt sent — check your phone and enter your PIN. This page updates automatically." : ""))
      );
      paymentSection.replaceChildren(form);

      const status = form.querySelector("#order-pay-status");
      form.addEventListener("submit", async (event) => {
        event.preventDefault();
        status.textContent = "Sending M-Pesa prompt…";
        try {
          const res = await fetch("/api/v1/payments/mpesa/stk-push", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ store_order_id: orderId, phone: new FormData(form).get("phone") }),
          });
          const data = await res.json();
          status.textContent = res.ok ? data.customer_message || "Check your phone and enter your M-Pesa PIN." : data.error || "Could not start payment.";
        } catch (e) {
          status.textContent = "Network error: " + e.message;
        }
      });
      form.querySelector("#cancel-order-btn").addEventListener("click", async () => {
        status.textContent = "Cancelling…";
        try {
          const res = await fetch(`/api/v1/store/orders/${orderId}/cancel`, { method: "POST" });
          const data = await res.json();
          if (!res.ok) {
            status.textContent = data.error || "Could not cancel this order.";
            return;
          }
          refresh();
        } catch (e) {
          status.textContent = "Network error: " + e.message;
        }
      });
    }

    async function refresh() {
      try {
        const res = await fetch(`/api/v1/store/orders/${orderId}`);
        const order = await res.json();
        if (!res.ok) {
          summary.replaceChildren(el("p", { class: "card__meta" }, order.error || "Could not load this order."));
          clearInterval(polling);
          return;
        }
        renderSummary(order);
        renderPayment(order);
        if (["paid", "fulfilled", "cancelled"].includes(order.status)) clearInterval(polling);
      } catch (e) {
        summary.replaceChildren(el("p", { class: "card__meta" }, "Network error: " + e.message));
      }
    }

    refresh();
    polling = setInterval(refresh, 5000);
  </script>
<?php endif; ?>
