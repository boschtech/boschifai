<template>
    <div class="relative inline-flex items-center justify-center shrink-0" :style="{ width: size + 'px', height: size + 'px' }">
        <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`" class="-rotate-90">
            <circle
                :cx="center" :cy="center" :r="radius"
                fill="none" stroke="#c0392b" :stroke-width="strokeWidth"
                stroke-linecap="round"
            />
            <circle
                :cx="center" :cy="center" :r="radius"
                fill="none" stroke="#27ae60" :stroke-width="strokeWidth"
                stroke-linecap="round"
                :stroke-dasharray="`${scoreLength} ${circumference}`"
                class="transition-[stroke-dasharray] duration-500 ease-out"
            />
        </svg>
        <span class="absolute text-lg font-bold text-fg-strong">{{ Math.round(score) }}%</span>
    </div>
</template>

<script>
export default {
    name: 'ScoreDonutChart',

    props: {
        score: { type: Number, required: true },
        size: { type: Number, default: 120 },
        strokeWidth: { type: Number, default: 14 },
    },

    computed: {
        center() {
            return this.size / 2;
        },

        radius() {
            return this.center - this.strokeWidth / 2;
        },

        circumference() {
            return 2 * Math.PI * this.radius;
        },

        scoreLength() {
            const clamped = Math.max(0, Math.min(100, this.score));
            return (clamped / 100) * this.circumference;
        },
    },
};
</script>
