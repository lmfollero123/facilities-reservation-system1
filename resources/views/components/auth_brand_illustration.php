<?php
/**
 * Decorative brand-panel illustration for the auth split layout (login/register).
 * Inline SVG so it scales crisply at any panel size with no photo asset to license/host —
 * a stylized barangay skyline (covered-court roof, community hall, palm trees) under a
 * sun-and-stars motif nodding to the Philippine flag, in the theme's green palette.
 */
?>
<svg class="auth-split-illustration" viewBox="0 0 600 800" preserveAspectRatio="xMidYMax slice" aria-hidden="true" focusable="false">
    <defs>
        <radialGradient id="authSunGlow" cx="50%" cy="50%" r="50%">
            <stop offset="0%" stop-color="#fde68a" stop-opacity="0.9" />
            <stop offset="55%" stop-color="#fbbf24" stop-opacity="0.35" />
            <stop offset="100%" stop-color="#fbbf24" stop-opacity="0" />
        </radialGradient>
    </defs>

    <!-- sun -->
    <circle cx="440" cy="150" r="150" fill="url(#authSunGlow)" />
    <circle cx="440" cy="150" r="58" fill="#fde68a" opacity="0.85" />

    <!-- stars (nod to the PH flag's three stars) -->
    <g fill="#ffffff" opacity="0.75">
        <path d="M120 90 l4 10 10 1 -8 7 3 10 -9 -6 -9 6 3 -10 -8 -7 10 -1z" />
        <path d="M340 60 l3 7 7 1 -5 5 2 7 -7 -4 -6 4 2 -7 -5 -5 7 -1z" />
        <path d="M500 260 l3 7 7 1 -5 5 2 7 -6 -4 -7 4 2 -7 -5 -5 7 -1z" />
    </g>

    <!-- distant palm silhouettes -->
    <g fill="#065f46" opacity="0.55">
        <path d="M70 430 q-4 -46 -34 -60 q30 4 38 34 q4 -34 -14 -56 q28 10 26 52 q10 -26 -4 -48 q22 14 16 50 q14 -18 10 -38 q10 20 -6 46 l4 40z" />
        <path d="M560 470 q-4 -40 -30 -52 q26 4 34 30 q4 -30 -12 -48 q24 8 22 46 q9 -22 -4 -42 q19 12 14 44 q12 -16 9 -34 q9 18 -5 40 l4 36z" />
    </g>

    <!-- barangay covered-court roof (gable) -->
    <path d="M0 560 L150 470 L300 560 Z" fill="#047857" opacity="0.9" />
    <rect x="40" y="560" width="220" height="16" fill="#047857" opacity="0.9" />

    <!-- community hall / bahay kubo silhouette -->
    <path d="M260 560 L400 480 L540 560 Z" fill="#059669" opacity="0.85" />
    <rect x="285" y="560" width="230" height="20" fill="#059669" opacity="0.85" />
    <rect x="370" y="500" width="60" height="60" fill="#065f46" opacity="0.7" />

    <!-- foreground skyline band -->
    <path d="M0 620 Q150 580 300 610 T600 605 V800 H0 Z" fill="#065f46" />
    <path d="M0 660 Q160 630 320 655 T600 645 V800 H0 Z" fill="#04432f" />

    <!-- foreground palm accents -->
    <g fill="#04321f" opacity="0.9">
        <path d="M60 660 q-6 -60 -46 -80 q40 4 52 46 q6 -46 -18 -76 q38 12 36 70 q14 -34 -4 -64 q30 18 22 68 q18 -24 14 -50 q14 26 -8 62 l6 24z" />
        <path d="M520 690 q-5 -50 -38 -66 q33 4 43 38 q5 -38 -15 -63 q31 10 30 58 q11 -28 -3 -53 q25 15 18 56 q15 -20 12 -42 q11 22 -7 51 l5 21z" />
    </g>
</svg>
