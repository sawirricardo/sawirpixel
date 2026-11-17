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
        <LegalDocument title="Privacy policy" :updated-at="updatedAt">
            <p>
                This policy explains what {{ operator }} ("we", "us") collects
                when you use {{ appName }}, why, who it's shared with, and the
                choices you have. It applies alongside our
                <Link href="/terms">terms of service</Link>.
            </p>

            <h2>What we collect</h2>
            <ul>
                <li>
                    <strong>Your account:</strong> your name, email address, and
                    password (stored only as a one-way hash, never in a form we
                    can read), and when you verified your email.
                </li>
                <li>
                    <strong>Organizations:</strong> the organizations you create
                    or join, your roles and permissions in them, invitations you
                    send or receive, and the limits set for them.
                </li>
                <li>
                    <strong>Generations:</strong> your prompts, the size and
                    quality you chose, the reference image links you add, the
                    status of each image, its estimated and actual cost, and the
                    link to the finished image while it's valid.
                </li>
                <li>
                    <strong>Credits:</strong> every purchase, amount set aside
                    and charge on your organization's balance, and who made it.
                    We don't receive or store card details.
                </li>
                <li>
                    <strong>Notifications</strong> we show you in the app, such
                    as when an image is ready.
                </li>
                <li>
                    <strong>Technical data:</strong> your IP address and browser
                    details in server logs, used to keep the service secure and
                    working, and to limit how often requests can be made.
                </li>
            </ul>

            <h2>Why we use it</h2>
            <ul>
                <li>
                    To provide {{ appName }}: generating your images, showing
                    your history, and running your organization.
                </li>
                <li>
                    To bill accurately: estimating prices, setting aside credits
                    and charging what each image actually cost.
                </li>
                <li>
                    To send the emails the service needs, such as verifying your
                    address, resetting your password and invitations.
                </li>
                <li>
                    To keep the service safe, including preventing abuse and
                    enforcing our terms.
                </li>
                <li>
                    To meet legal obligations, such as keeping financial
                    records.
                </li>
            </ul>
            <p>
                We don't sell your data, use it for advertising, or use your
                prompts or images to train models.
            </p>

            <h2>Who we share it with</h2>
            <ul>
                <li>
                    <strong>Our image generation provider</strong> receives your
                    prompt, chosen options and reference image links to make
                    each image, and hosts the finished image for 24 hours.
                </li>
                <li>
                    <strong>Our email provider</strong> receives your address
                    and the content of emails we send you.
                </li>
                <li>
                    <strong>Our hosting providers</strong> store and process
                    data on our behalf to run the service.
                </li>
                <li>
                    <strong>Your organization:</strong> its owners and admins
                    can see members' names, email addresses and generations, and
                    the organization's credit history.
                </li>
                <li><strong>Authorities</strong>, when the law requires it.</li>
            </ul>

            <h2>Cookies</h2>
            <p>
                We only use cookies needed for {{ appName }} to work: one keeps
                you signed in, and one protects forms against cross-site request
                forgery. There are no analytics or advertising cookies.
            </p>

            <h2>How long we keep it</h2>
            <ul>
                <li>
                    Account and organization data: for as long as the account or
                    organization exists.
                </li>
                <li>
                    Finished images: we don't keep them. The provider's link
                    expires 24 hours after an image is generated.
                </li>
                <li>
                    Generation and credit records: kept for as long as needed
                    for billing, tax and accounting, including after an
                    organization is deleted.
                </li>
                <li>
                    Server logs: kept for a limited period for security and
                    troubleshooting.
                </li>
            </ul>

            <h2>Your choices and rights</h2>
            <p>
                You can update your name and email address in your profile at
                any time. Depending on where you live, you may also have the
                right to access, correct, export or delete your personal data,
                or to object to how we use it. To make a request, contact us
                using the details below; we'll respond within the time the law
                requires. You can also complain to your local data protection
                authority.
            </p>

            <h2>Security</h2>
            <p>
                Data is sent over encrypted connections, passwords are hashed,
                and access to data is limited to what's needed to run the
                service. No system is perfectly secure, but we'll tell you
                without undue delay if a breach affects your data.
            </p>

            <h2>Changes</h2>
            <p>
                If we change this policy, we'll update the date at the top. For
                significant changes, we'll let account holders know by email or
                in the app.
            </p>

            <h2>Contact</h2>
            <p>
                Privacy questions and requests:
                <a v-if="contactEmail" :href="`mailto:${contactEmail}`">{{
                    contactEmail
                }}</a>
                <template v-else>contact {{ operator }}.</template>
            </p>
        </LegalDocument>
    </AppLayout>
</template>
