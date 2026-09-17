<?php
/** @var string|null $error */
/** @var array $old */
use Laundry\Core\View;
?>
<h1>Sign up</h1>
<p style="max-width: var(--ac-measure); margin-block: var(--ac-space-2) var(--ac-space-6);">
  Create an account to book a pickup, or sign up as an operator to receive bookings.
</p>

<?php if ($error): ?>
  <p class="card__meta" role="alert" style="color: #b3261e;"><?= View::e($error) ?></p>
<?php endif; ?>

<form method="post" action="/signup" style="max-width: 28rem; display:flex; flex-direction:column; gap: var(--ac-space-4);">
  <label>
    Full name
    <input type="text" name="full_name" required value="<?= View::e($old['full_name'] ?? '') ?>" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>
  <label>
    Phone number
    <input type="tel" name="phone_number" placeholder="0712345678" required value="<?= View::e($old['phone_number'] ?? '') ?>" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>
  <label>
    Email (optional)
    <input type="email" name="email" value="<?= View::e($old['email'] ?? '') ?>" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>
  <label>
    Password
    <input type="password" name="password" minlength="8" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>
  <fieldset style="border: 1px solid #ddd; padding: var(--ac-space-3);">
    <legend>I am a&hellip;</legend>
    <label style="display:block;">
      <input type="radio" name="account_type" value="customer" <?= ($old['account_type'] ?? 'customer') === 'customer' ? 'checked' : '' ?>>
      Customer booking laundry service
    </label>
    <label style="display:block;">
      <input type="radio" name="account_type" value="provider" <?= ($old['account_type'] ?? '') === 'provider' ? 'checked' : '' ?>>
      Laundry operator wanting bookings
    </label>
  </fieldset>
  <button type="submit" class="btn btn--primary">Create account</button>
</form>

<p class="card__meta" style="margin-top: var(--ac-space-4);">Already have an account? <a href="/login">Log in</a></p>
