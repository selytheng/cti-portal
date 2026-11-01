<script setup>
import { computed, ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link, usePage } from '@inertiajs/vue3';

// Left collapsible SOC sidebar (spec Section 1.2), state persisted locally.
const collapsed = ref(JSON.parse(localStorage.getItem('sidebarCollapsed') ?? 'false'));
const mobileOpen = ref(false);

function toggleSidebar() {
    collapsed.value = !collapsed.value;
    localStorage.setItem('sidebarCollapsed', JSON.stringify(collapsed.value));
}

const page = usePage();

const navItems = computed(() => [
    { label: 'Dashboard', icon: 'grid', href: route('dashboard'), active: route().current('dashboard'), show: true },
    { label: 'Query Hub', icon: 'bolt', href: route('queryhub.index'), active: route().current('queryhub.*'), show: true },
    { label: 'CTI Repository', icon: 'database', href: route('cti.index'), active: route().current('cti.*'), show: true },
    {
        label: 'Configuration Center',
        icon: 'sliders',
        href: route('config.index'),
        active: route().current('config.*'),
        show: page.props.can?.configManage ?? false,
    },
    {
        label: 'User Management',
        icon: 'users',
        href: route('users.index'),
        active: route().current('users.*'),
        show: page.props.can?.manageUsers ?? false,
    },
    {
        label: 'Audit Logs',
        icon: 'shield',
        href: route('audit.index'),
        active: route().current('audit.*'),
        show: page.props.can?.viewAudit ?? false,
    },
].filter((item) => item.show));
</script>

<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
        <div class="flex min-h-screen">
            <!-- ===== Sidebar (desktop) ===== -->
            <aside
                class="hidden shrink-0 flex-col border-e border-gray-200 bg-white transition-all duration-200 sm:flex dark:border-gray-700 dark:bg-gray-800"
                :class="collapsed ? 'w-16' : 'w-64'"
            >
                <!-- Logo + collapse toggle -->
                <div class="flex h-16 items-center justify-between px-3">
                    <Link :href="route('dashboard')" class="flex items-center gap-2 overflow-hidden">
                        <ApplicationLogo class="block h-9 w-9 shrink-0 fill-current text-gray-800 dark:text-gray-200" />
                        <span
                            v-if="!collapsed"
                            class="whitespace-nowrap text-sm font-bold tracking-wide text-gray-800 dark:text-gray-200"
                        >
                            CTI Portal
                        </span>
                    </Link>
                    <button
                        type="button"
                        @click="toggleSidebar"
                        class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                        title="Toggle sidebar"
                    >
                        <svg class="h-5 w-5 transition-transform" :class="collapsed ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                        </svg>
                    </button>
                </div>

                <!-- Nav -->
                <nav class="flex-1 space-y-1 overflow-y-auto px-2 py-3">
                    <Link
                        v-for="item in navItems"
                        :key="item.label"
                        :href="item.href"
                        class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors"
                        :class="item.active
                            ? 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400'
                            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white'"
                        :title="collapsed ? item.label : undefined"
                    >
                        <!-- icons -->
                        <svg v-if="item.icon === 'grid'" class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                        <svg v-else-if="item.icon === 'bolt'" class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <svg v-else-if="item.icon === 'database'" class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                        <svg v-else-if="item.icon === 'sliders'" class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <svg v-else-if="item.icon === 'users'" class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                        <svg v-else-if="item.icon === 'shield'" class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        <span v-if="!collapsed" class="whitespace-nowrap">{{ item.label }}</span>
                    </Link>
                </nav>

                <!-- MFA quick access -->
                <div class="border-t border-gray-200 p-2 dark:border-gray-700">
                    <Link
                        :href="route('mfa.setup')"
                        class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white"
                        :title="collapsed ? 'Multi-Factor Auth' : undefined"
                    >
                        <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        <span v-if="!collapsed" class="whitespace-nowrap">Multi-Factor Auth</span>
                    </Link>
                </div>
            </aside>

            <!-- ===== Mobile sidebar overlay ===== -->
            <div v-if="mobileOpen" class="fixed inset-0 z-40 sm:hidden">
                <div class="absolute inset-0 bg-gray-600/75" @click="mobileOpen = false" />
                <aside class="absolute inset-y-0 start-0 flex w-64 flex-col bg-white dark:bg-gray-800">
                    <div class="flex h-16 items-center px-3">
                        <ApplicationLogo class="block h-9 w-9 fill-current text-gray-800 dark:text-gray-200" />
                        <span class="ms-2 text-sm font-bold text-gray-800 dark:text-gray-200">CTI Portal</span>
                    </div>
                    <nav class="flex-1 space-y-1 px-2 py-3">
                        <Link
                            v-for="item in navItems"
                            :key="item.label"
                            :href="item.href"
                            @click="mobileOpen = false"
                            class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium"
                            :class="item.active
                                ? 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400'
                                : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                        >
                            {{ item.label }}
                        </Link>
                    </nav>
                </aside>
            </div>

            <!-- ===== Main column ===== -->
            <div class="flex min-w-0 flex-1 flex-col">
                <!-- Top command bar -->
                <nav class="border-b border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex h-16 items-center justify-between px-4 sm:px-6">
                        <div class="flex items-center gap-3">
                            <button
                                type="button"
                                class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 sm:hidden dark:hover:bg-gray-700 dark:hover:text-gray-300"
                                @click="mobileOpen = true"
                            >
                                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                            </button>
                            <span class="hidden text-xs text-gray-400 sm:inline dark:text-gray-500">
                                {{ collapsed ? '☰' : '' }} SOC threat-intelligence workspace
                            </span>
                        </div>

                        <div class="flex items-center">
                            <!-- User dropdown -->
                            <Dropdown align="right" width="48">
                                <template #trigger>
                                    <span class="inline-flex rounded-md">
                                        <button
                                            type="button"
                                            class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition hover:text-gray-700 focus:outline-none dark:bg-gray-800 dark:text-gray-400 dark:hover:text-gray-300"
                                        >
                                            {{ $page.props.auth.user.name }}
                                            <span
                                                v-for="role in $page.props.auth.user.roles"
                                                :key="role.id"
                                                class="ms-1 rounded-full bg-indigo-500/20 px-2 py-0.5 text-xs font-semibold text-indigo-600 dark:text-indigo-400"
                                            >
                                                {{ role.name }}
                                            </span>
                                            <svg class="-me-0.5 ms-2 h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                            </svg>
                                        </button>
                                    </span>
                                </template>
                                <template #content>
                                    <DropdownLink :href="route('profile.edit')">Profile</DropdownLink>
                                    <DropdownLink :href="route('mfa.setup')">Multi-Factor Auth</DropdownLink>
                                    <DropdownLink :href="route('logout')" method="post" as="button">Log Out</DropdownLink>
                                </template>
                            </Dropdown>
                        </div>
                    </div>
                </nav>

                <!-- Page Heading -->
                <header v-if="$slots.header" class="bg-white shadow dark:bg-gray-800">
                    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        <slot name="header" />
                    </div>
                </header>

                <!-- Page Content -->
                <main>
                    <slot />
                </main>
            </div>
        </div>
    </div>
</template>
