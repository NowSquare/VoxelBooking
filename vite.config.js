import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(),
    ],
    publicDir: false,
    build: {
        outDir: 'public/assets',
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: {
                admin: 'resources/js/admin/app.js',
                booking: 'resources/js/booking/app.js',
                'customer-access': 'resources/js/booking/customer-access.js',
                'agent-review': 'resources/js/agent-review.js',
                'admin-css': 'resources/css/admin.css',
                'booking-css': 'resources/css/booking.css',
            },
            output: {
                entryFileNames: 'js/[name].js',
                chunkFileNames: 'js/[name]-[hash].js',
                assetFileNames: (assetInfo) => {
                    if (assetInfo.name && assetInfo.name.endsWith('.css')) {
                        return 'css/[name].[ext]';
                    }
                    return 'assets/[name]-[hash].[ext]';
                },
            },
        },
    },
    server: {
        origin: 'http://localhost:5173',
    },
});
