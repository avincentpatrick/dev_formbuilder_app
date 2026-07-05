<script setup lang="ts">
// Placeholder authenticated tenant page (proves the subdomain + tenant.context + auth pipeline).
// The real dashboard inside the design-system app shell lands in Increment C.
</script>

<template>
  <main>
    <h1>Dashboard</h1>
    <p>You are signed in to a tenant workspace.</p>
    <form method="POST" action="/logout">
      <button type="submit">Log out</button>
    </form>
  </main>
</template>
