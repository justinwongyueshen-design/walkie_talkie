<?php
/**
 * @var string $channel
 * @var string $nickname
 * @var array<string,mixed> $clientConfig
 */
?>
<div class="wt-app">
    <header class="wt-header">
        <div class="wt-brand">
            <i class="fa-solid fa-walkie-talkie" aria-hidden="true"></i>
            <span>WALKIE TALKIE</span>
        </div>
        <div id="connStatus" class="wt-status" data-state="connecting" role="status" aria-live="polite">
            <span class="wt-dot" aria-hidden="true"></span>
            <i class="fa-solid fa-wifi" aria-hidden="true"></i>
            <span id="connStatusText">CONNECTING</span>
        </div>
    </header>

    <div class="wt-channelbar">
        <h1 class="wt-channel"><span aria-hidden="true">#</span> <span class="visually-hidden">Channel</span> <?= e(strtoupper($channel)) ?></h1>
        <span class="wt-you"><i class="fa-solid fa-user" aria-hidden="true"></i> <?= e($nickname) ?></span>
    </div>

    <main class="wt-main">
        <section class="wt-radio" aria-label="Push to talk">
            <div id="speakerPanel" class="wt-speaker" data-mode="idle">
                <div id="liveBadge" class="wt-live" hidden><span class="wt-live-dot" aria-hidden="true"></span> LIVE</div>
                <div id="speakerText" class="wt-speaker-text" aria-live="polite">Waiting for a speaker</div>
            </div>

            <button id="pttButton" type="button" class="wt-ptt" aria-pressed="false"
                    aria-label="Push to talk. Press and hold to transmit. Keyboard: hold the space bar.">
                <span class="wt-ptt-icon" aria-hidden="true"><i class="fa-solid fa-microphone"></i></span>
                <span id="pttLabel" class="wt-ptt-label">HOLD TO TALK</span>
            </button>
            <p class="wt-hint">Hold the button, or hold <kbd>Space</kbd></p>

            <div class="wt-level" aria-hidden="true">
                <canvas id="levelCanvas" width="640" height="120"></canvas>
            </div>

            <div class="wt-mic">
                <i id="micIcon" class="fa-solid fa-microphone-slash" aria-hidden="true"></i>
                <span id="micStatus" role="status">Microphone: OFF</span>
                <button id="micEnableBtn" type="button" class="btn btn-sm wt-ghost-btn">Enable microphone</button>
            </div>

            <button id="audioUnlockBtn" type="button" class="btn wt-warn-btn" hidden>
                <i class="fa-solid fa-volume-high" aria-hidden="true"></i> Tap to enable audio
            </button>

            <div id="notice" class="wt-notice" role="alert" hidden></div>
        </section>

        <aside class="wt-users" aria-labelledby="usersTitle">
            <div class="wt-users-head">
                <h2 id="usersTitle" class="wt-users-title">ON THIS CHANNEL</h2>
                <span id="userCount" class="wt-count"><i class="fa-solid fa-users" aria-hidden="true"></i> 0 USERS</span>
            </div>
            <ul id="userList" class="wt-user-list" aria-label="Users on this channel"></ul>
        </aside>
    </main>

    <footer class="wt-footer">
        <button id="leaveBtn" type="button" class="btn wt-leave-btn">
            <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Leave Channel
        </button>
    </footer>
</div>

<div id="fatalBox" class="wt-fatal" role="alertdialog" aria-labelledby="fatalText" hidden>
    <div class="wt-fatal-card">
        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
        <p id="fatalText"></p>
        <a id="fatalLink" class="btn wt-join-btn" href="<?= e(url('/')) ?>">Back to join page</a>
    </div>
</div>

<div id="remoteAudio" class="visually-hidden" aria-hidden="true"></div>

<script type="application/json" id="wt-config"><?= json_for_html($clientConfig) ?></script>
