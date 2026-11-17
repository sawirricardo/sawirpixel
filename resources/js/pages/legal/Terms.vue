<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import LegalDocument from '@/components/LegalDocument.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineProps<{
    appName: string;
    operator: string;
    contactEmail: string | null;
    updatedAt: string;
}>();
</script>

<template>
    <AppLayout>
        <LegalDocument title="Terms of service" :updated-at="updatedAt">
            <p>
                These terms are an agreement between you and
                {{ operator }} ("we", "us") for your use of {{ appName }}, a
                service that turns text prompts into images. By creating an
                account or using {{ appName }}, you agree to them. If you use
                {{ appName }} on behalf of a company or other organization, you
                agree to them on its behalf and confirm you're allowed to.
            </p>

            <h2>Your account</h2>
            <ul>
                <li>
                    You need an account with a verified email address to
                    generate images.
                </li>
                <li>
                    Keep your password safe. You're responsible for what happens
                    under your account.
                </li>
                <li>
                    You must be old enough to agree to these terms where you
                    live, and at least 16.
                </li>
            </ul>

            <h2>Organizations</h2>
            <p>
                Images, credits and limits belong to an organization. Whoever
                creates an organization becomes its owner, and owners and admins
                decide who else is a member, what each member may do, and the
                organization's spending and usage limits. Members' generations
                are visible to the organization and paid for from its credits.
                Owners can delete an organization; its billing records are kept
                as described in our <Link href="/privacy">privacy policy</Link>.
            </p>

            <h2>Credits and pricing</h2>
            <ul>
                <li>
                    Generating an image is paid for with prepaid credits held by
                    your organization.
                </li>
                <li>
                    Before you generate, we show an estimated price range. When
                    you submit, we set aside the top of that range. When the
                    image finishes, you're charged what it actually cost, which
                    is never more than the amount set aside, and the rest is
                    returned to your balance.
                </li>
                <li>If a generation fails, you're not charged.</li>
                <li>
                    Prices are based on the resources each image uses and may
                    change. A change never affects an image that's already been
                    submitted.
                </li>
                <li>
                    Credits aren't money, can't be transferred between
                    organizations, and aren't refundable except where the law
                    requires it.
                </li>
            </ul>

            <h2>Acceptable use</h2>
            <p>
                You agree not to use {{ appName }} to create, request or share
                anything that:
            </p>
            <ul>
                <li>is illegal, or sexualizes minors in any way;</li>
                <li>
                    harasses, threatens or defames someone, or depicts a real
                    person in a sexual or degrading way;
                </li>
                <li>
                    infringes someone else's intellectual property or privacy
                    rights;
                </li>
                <li>
                    is meant to deceive, such as fake documents or
                    impersonation;
                </li>
                <li>
                    promotes violence, self-harm or hatred against people based
                    on who they are.
                </li>
            </ul>
            <p>
                Don't try to get around our limits or safety checks, overload
                the service, or access it other than through the interfaces we
                provide. Prompts can be refused automatically; a refused prompt
                isn't charged. We may suspend accounts or organizations that
                break these rules.
            </p>

            <h2>Your prompts and images</h2>
            <ul>
                <li>
                    You keep whatever rights you have in your prompts and the
                    reference images you provide, and you confirm you're allowed
                    to use them.
                </li>
                <li>
                    As between you and us, you own the images you generate, to
                    the extent the law allows. Similar prompts can produce
                    similar images for other people, and images generated by a
                    machine may not be protected by copyright everywhere.
                </li>
                <li>
                    You're responsible for how you use the images you generate.
                </li>
                <li>
                    <strong>We don't store your images.</strong> Each image is
                    available from a link that expires 24 hours after it's
                    generated. Download anything you want to keep before then;
                    after it expires, the image can't be recovered or continued.
                </li>
            </ul>

            <h2>Availability and changes</h2>
            <p>
                We work to keep {{ appName }} running, but it's provided "as is"
                and may sometimes be unavailable, slow, or produce results that
                don't match your prompt. We may change, add or remove features.
                If we change these terms in a way that matters, we'll update the
                date at the top and, for significant changes, let account
                holders know by email or in the app. Continuing to use
                {{ appName }} after a change means you accept it.
            </p>

            <h2>Ending your use</h2>
            <p>
                You can stop using {{ appName }} at any time. We may suspend or
                close accounts that break these terms or that we're required to
                close by law. Unused credits of an organization closed for
                breaking these terms are not refunded.
            </p>

            <h2>Liability</h2>
            <p>
                To the extent the law allows, we aren't liable for indirect or
                consequential losses, or for lost profits, data or goodwill, and
                our total liability for any claim relating to {{ appName }} is
                limited to the amount your organization paid us for credits in
                the three months before the claim. Nothing in these terms limits
                liability that can't be limited by law.
            </p>

            <h2>Contact</h2>
            <p>
                Questions about these terms:
                <a v-if="contactEmail" :href="`mailto:${contactEmail}`">{{
                    contactEmail
                }}</a>
                <template v-else>contact {{ operator }}.</template>
            </p>
        </LegalDocument>
    </AppLayout>
</template>
