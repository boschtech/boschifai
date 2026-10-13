<template>
    <div class="max-w-xl">
        <h1 class="text-xl font-bold text-fg-strong mb-4">Payment</h1>

        <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>

        <div v-else-if="!payment || payment.status === 'pending'" class="rounded-md border border-border bg-surface p-4 text-sm text-fg" data-state="pending">
            <template v-if="!timedOut">Waiting for the payment provider to confirm your payment…</template>
            <template v-else>
                This is taking longer than usual. Your credits will appear on the billing page as soon as the
                payment provider confirms the payment — you can leave this page. Please don't pay again.
            </template>
        </div>

        <div v-else-if="payment.status === 'succeeded'" class="rounded-md border border-success/30 bg-success/10 p-4 text-sm text-fg" data-state="succeeded">
            Payment received — <strong>{{ payment.credits_usd }} credits</strong> were added to this workspace.
        </div>

        <div v-else-if="payment.status === 'needs_review'" class="rounded-md border border-warning/30 bg-warning/10 p-4 text-sm text-fg" data-state="needs_review">
            We received a payment but it doesn't match what we expected, so it's being checked before credits are
            added. You'll hear from us — please don't pay again.
        </div>

        <div v-else class="rounded-md border border-danger/30 bg-danger/10 p-4 text-sm text-danger" data-state="failed">
            The payment didn't go through, and no credits were added.
        </div>

        <router-link :to="{ name: 'billing' }" class="mt-4 inline-block text-sm font-medium text-primary transition hover:text-primary-dark">
            Back to billing
        </router-link>
    </div>
</template>

<script>
// Where the payment provider sends the customer back to. The webhook that grants the credits can
// arrive a little after the customer does, so this checks the payment until it settles.
const POLL_MS = 3000;
const MAX_POLLS = 40; // about two minutes

export default {
    name: 'BillingReturnPage',

    props: {
        id: { type: [String, Number], required: true },
    },

    data() {
        return { payment: null, error: null, polls: 0, timer: null };
    },

    computed: {
        timedOut() {
            return this.polls >= MAX_POLLS;
        },
    },

    created() {
        this.check();
    },

    beforeDestroy() {
        clearTimeout(this.timer);
    },

    methods: {
        async check() {
            try {
                this.payment = await this.$store.dispatch('billing/fetchPayment', this.id);
            } catch (e) {
                this.error = e.response?.data?.message || e.message;
                return;
            }

            this.polls += 1;
            if (this.payment.status === 'pending' && !this.timedOut) {
                this.timer = setTimeout(() => this.check(), POLL_MS);
            }
        },
    },
};
</script>
