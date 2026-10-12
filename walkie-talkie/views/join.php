<?php
/**
 * @var string $csrf
 * @var string $nickname
 * @var string $channel
 * @var string|null $error
 * @var string $publicUrl
 * @var int $maxPeers
 */
?>
<main class="wt-join">
    <div class="wt-join-card">
        <div class="wt-logo" aria-hidden="true"><i class="fa-solid fa-walkie-talkie"></i></div>
        <h1 class="wt-title">WALKIE TALKIE</h1>
        <p class="wt-tagline">Push to Talk<br><span>Instant Voice Communication</span></p>

        <?php if (!empty($error)): ?>
            <div class="wt-alert" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/join')) ?>" class="wt-form" novalidate>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <label for="nickname" class="wt-label">Nickname</label>
            <input type="text" id="nickname" name="nickname" class="form-control wt-input"
                   value="<?= e($nickname) ?>" maxlength="24" required autocomplete="nickname"
                   placeholder="Enter your nickname" autocapitalize="words" spellcheck="false">

            <label for="channel" class="wt-label">Channel</label>
            <input type="text" id="channel" name="channel" class="form-control wt-input"
                   value="<?= e($channel) ?>" maxlength="64" required autocomplete="off"
                   placeholder="Enter channel name" autocapitalize="none" spellcheck="false"
                   aria-describedby="channelHelp">
            <div id="channelHelp" class="wt-help">
                Enter the same channel name as your team members to communicate together.
                Up to <?= (int) $maxPeers ?> users per channel.
            </div>

            <button type="submit" class="btn wt-join-btn"><i class="fa-solid fa-tower-broadcast" aria-hidden="true"></i> JOIN CHANNEL</button>
        </form>

        <section class="wt-qr" aria-labelledby="qrTitle">
            <h2 id="qrTitle" class="wt-qr-title">Open on your phone</h2>
            <canvas id="qrCanvas" class="wt-qr-canvas" data-url="<?= e($publicUrl) ?>" width="180" height="180"
                    role="img" aria-label="QR code that opens this application"></canvas>
            <p class="wt-help mb-1">Scan this code to join</p>
            <p class="wt-help wt-qr-url" id="qrUrl"><?= e($publicUrl) ?></p>
            <p class="wt-help wt-warn" id="qrLocalhostHint" hidden>
                This address only works on this computer. Set <code>APP_PUBLIC_URL</code> in <code>.env</code>
                to your LAN IP address (for example <code>http://192.168.1.20/walkie-talkie</code>).
            </p>
        </section>

        <div class="wt-install">
            <button type="button" id="installBtn" class="btn btn-sm wt-ghost-btn" hidden>
                <i class="fa-solid fa-download" aria-hidden="true"></i> Install app
            </button>
            <p id="iosHint" class="wt-help" hidden>
                iPhone / iPad: tap <strong>Share</strong>, then <strong>Add to Home Screen</strong>.
            </p>
        </div>
    </div>
</main>
