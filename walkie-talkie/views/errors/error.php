<?php
/**
 * @var int $status
 * @var string $message
 */
?>
<main class="wt-join">
    <div class="wt-join-card text-center">
        <div class="wt-logo" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <h1 class="wt-title"><?= (int) $status ?></h1>
        <p class="wt-tagline"><?= e($message) ?></p>
        <a class="btn wt-join-btn" href="<?= e(url('/')) ?>">Back to start</a>
    </div>
</main>
