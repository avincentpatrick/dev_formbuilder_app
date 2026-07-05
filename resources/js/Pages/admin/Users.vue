<script setup lang="ts">
// Unstyled Increment-B2c super-admin cross-tenant user list (styled in Increment C). The rows here are
// visible only because SuperAdminService read them through the elevated `superadmin_bypass` RLS policy.
defineProps<{
  users: Array<{ id: string; name: string; email: string }>;
}>();
</script>

<template>
  <main>
    <h1>All users</h1>
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="user in users" :key="user.id">
          <td>{{ user.name }}</td>
          <td>{{ user.email }}</td>
        </tr>
      </tbody>
    </table>
  </main>
</template>
