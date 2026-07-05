<script setup lang="ts">
// Unstyled Increment-B2c super-admin tenant console (styled in Increment C).
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
  tenants: Array<{ id: string; name: string; slug: string; status: string }>;
}>();

// Business-rule failures (e.g. suspending an already-suspended tenant) come back as a shared `admin`
// session error, not a form-field error.
const page = usePage<{ errors: Record<string, string> }>();
const adminError = computed(() => page.props.errors?.admin);

function suspend(id: string): void {
  router.post(`/admin/tenants/${id}/suspend`);
}

function reactivate(id: string): void {
  router.post(`/admin/tenants/${id}/reactivate`);
}
</script>

<template>
  <main>
    <h1>Tenants</h1>
    <p v-if="adminError">{{ adminError }}</p>

    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Slug</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="tenant in tenants" :key="tenant.id">
          <td>{{ tenant.name }}</td>
          <td>{{ tenant.slug }}</td>
          <td>{{ tenant.status }}</td>
          <td>
            <button v-if="tenant.status !== 'suspended'" type="button" @click="suspend(tenant.id)">
              Suspend
            </button>
            <button v-else type="button" @click="reactivate(tenant.id)">Reactivate</button>
          </td>
        </tr>
      </tbody>
    </table>
  </main>
</template>
