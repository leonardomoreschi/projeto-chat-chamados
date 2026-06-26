<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Chat Interno</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/light-mode.css">
    <style>
        #neat-gradient {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: 0;
        }
        body > *:not(#neat-gradient) {
            position: relative;
            z-index: 1;
        }
        a[href*="firecms"], a[href*="neat"] {
            display: none !important;
        }
    </style>
</head>
<body class="page-login min-h-screen flex items-center justify-center p-4" style="background:#010101">

<canvas id="neat-gradient"></canvas>


<div class="w-full max-w-sm">

    <!-- Logo / título -->
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-14 h-14 bg-indigo-600 rounded-2xl mb-4">
            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-white">Chat Interno</h1>
        <p class="text-gray-400 text-sm mt-1">Entre com suas credenciais</p>
    </div>

    <!-- Card do formulário -->
    <div class="rounded-2xl p-8 shadow-2xl" style="background:rgba(10,10,18,0.72);border:1px solid rgba(255,255,255,0.07);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px)">

        <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm rounded-xl px-4 py-3 mb-6 flex items-center gap-2">
            <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
            </svg>
            <?= htmlspecialchars($_SESSION['flash_error']) ?>
            <?php unset($_SESSION['flash_error']); ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="/login" class="space-y-5">

            <div>
                <label for="email" class="block text-sm font-medium text-gray-300 mb-2">E-mail</label>
                <input type="email" id="email" name="email" required autofocus
                       placeholder="seu@empresa.com"
                       class="w-full bg-gray-800 border border-gray-700 text-white placeholder-gray-500 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition">
            </div>

            <div>
                <label for="senha" class="block text-sm font-medium text-gray-300 mb-2">Senha</label>
                <input type="password" id="senha" name="senha" required
                       placeholder="••••••••"
                       class="w-full bg-gray-800 border border-gray-700 text-white placeholder-gray-500 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition">
            </div>

            <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold rounded-xl py-3 text-sm transition-colors duration-200 mt-2">
                Entrar
            </button>

        </form>
    </div>

    <p class="text-center text-gray-600 text-xs mt-6">Chat Interno &copy; <?= date('Y') ?></p>
</div>

<script src="/assets/js/theme.js"></script>
<script type="module">
import { NeatGradient } from "https://esm.sh/@firecms/neat@latest";

const gradient = new NeatGradient({
    ref: document.getElementById("neat-gradient"),
    colors: [
        { color: '#000000', enabled: true },
        { color: '#001129', enabled: true },
        { color: '#0F0025', enabled: true },
        { color: '#14080A', enabled: true },
        { color: '#001129', enabled: true },
    ],
    speed: 4,
    horizontalPressure: 4,
    verticalPressure: 4,
    waveFrequencyX: 3,
    waveFrequencyY: 2,
    waveAmplitude: 1,
    shadows: 2,
    highlights: 6,
    colorBrightness: 1,
    colorSaturation: -2,
    wireframe: false,
    colorBlending: 7,
    backgroundColor: '#010101',
    backgroundAlpha: 0.8,
    grainScale: 0,
    grainSparsity: 0,
    grainIntensity: 0.325,
    grainSpeed: 1,
    resolution: 1.25,
    yOffset: 3278,
    yOffsetWaveMultiplier: 2.2,
    yOffsetColorMultiplier: 2.5,
    yOffsetFlowMultiplier: 2.8,
    flowDistortionA: 3.7,
    flowDistortionB: 1.4,
    flowScale: 2.9,
    flowEase: 0.32,
    flowEnabled: false,
    enableProceduralTexture: false,
    transparentTextureVoid: false,
    textureVoidLikelihood: 0.27,
    textureVoidWidthMin: 60,
    textureVoidWidthMax: 420,
    textureBandDensity: 1.2,
    textureColorBlending: 0.06,
    textureSeed: 333,
    textureEase: 0.68,
    proceduralBackgroundColor: '#0E0707',
    textureShapeTriangles: 20,
    textureShapeCircles: 15,
    textureShapeBars: 15,
    textureShapeSquiggles: 10,
    domainWarpEnabled: false,
    domainWarpIntensity: 0,
    domainWarpScale: 3,
    vignetteIntensity: 0,
    vignetteRadius: 0.8,
    fresnelEnabled: false,
    fresnelPower: 2,
    fresnelIntensity: 0.5,
    fresnelColor: '#FFFFFF',
    iridescenceEnabled: false,
    iridescenceIntensity: 0.5,
    iridescenceSpeed: 1,
    bloomIntensity: 0.7,
    bloomThreshold: 0.75,
    chromaticAberration: 0,
    shapeType: 'plane',
    shapeRotationX: 0,
    shapeRotationY: 0,
    shapeRotationZ: 0,
    shapeAutoRotateSpeedX: 0,
    shapeAutoRotateSpeedY: 0,
    sphereRadius: 15,
    torusRadius: 15,
    torusTube: 5,
    cylinderRadius: 10,
    cylinderHeight: 40,
    planeBend: 0,
    planeTwist: 0,
    silhouetteFade: 0.25,
    cylinderFade: 0.08,
    ribbonFade: 0.05,
    flatShading: true,
    cameraLock: true,
    cameraX: 0,
    cameraY: 0,
    cameraZ: 0,
    cameraRotationX: 0,
    cameraRotationY: 0,
    cameraRotationZ: 0,
    cameraZoom: 1,
});

const removeWatermark = () => {
    document.querySelectorAll('a[href*="firecms"], a[href*="neat"]').forEach(el => el.remove());
};
new MutationObserver(removeWatermark).observe(document.body, { childList: true, subtree: true });
setTimeout(removeWatermark, 500);
</script>
</body>
</html>
