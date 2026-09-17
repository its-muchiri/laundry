<?php
/** @var array $operators */
use Laundry\Core\View;
?>
<section style="padding-block: var(--ac-space-8) var(--ac-space-12);">
  <h1 style="font-size: 2.5rem; max-width: 32rem;">Fresh laundry, picked up and delivered — book in a minute.</h1>
  <p style="max-width: var(--ac-measure); margin-block: var(--ac-space-4);">
    Verified laundry operators near you, transparent pricing, and M-Pesa payment — no phone-call negotiation.
  </p>
  <a href="/book" class="btn btn--primary">Book a pickup</a>
</section>

<section>
  <h2>Trusted operators near you</h2>
  <?php if (empty($operators)): ?>
    <p class="card__meta">No operators are onboarded yet in this environment — see src/Controllers/OnboardingController.php to add one, or seed the <code>users</code>/<code>operator_quality_scores</code> tables directly for a demo.</p>
  <?php else: ?>
    <div style="display:flex; flex-wrap:wrap; gap: var(--ac-space-4);">
      <?php foreach ($operators as $operator): ?>
        <div class="card" style="width: 16rem;">
          <h3><?= View::e($operator['full_name']) ?></h3>
          <div class="card__meta">
            <?= $operator['average_rating'] !== null ? number_format((float) $operator['average_rating'], 1) . ' ★' : 'No ratings yet' ?>
            · <?= (int) ($operator['completed_orders_count'] ?? 0) ?> orders completed
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
