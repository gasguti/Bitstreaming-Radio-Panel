<template>
    <footer id="footer">
        {{ $gettext('Powered by') }}
        <a
            href="https://www.azuracast.com/"
            target="_blank"
        >Bitstreaming Radio Panel</a>
        &bull;
        <span v-html="version"/>
        &bull;
        <span v-html="platform"/>
    </footer>
</template>

<script setup lang="ts">
import { useAzuraCastDashboardGlobals } from "~/vendor/azuracast.ts";

const { version, platform } = useAzuraCastDashboardGlobals();
</script>
