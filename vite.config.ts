import path from 'path';
import fs from 'fs';
import { defineConfig, Plugin } from 'vite';

function blogRewriterPlugin(): Plugin {
  return {
    name: 'blog-rewriter-plugin',
    configureServer(server) {
      server.middlewares.use((req, res, next) => {
        if (req.url && req.url.startsWith('/blog/')) {
          const urlWithoutQuery = req.url.split('?')[0];
          // Skip static files with extensions
          if (!urlWithoutQuery.match(/\.(css|js|png|jpg|jpeg|gif|svg|ico|json|webmanifest|woff2?|ttf)$/i)) {
            const cleanPath = urlWithoutQuery.replace(/\/$/, '');
            const relPath = cleanPath.slice(1); // e.g. "blog/gperya-login-not-working"
            const staticHtmlFile = path.resolve(__dirname, relPath, 'index.html');
            
            // If it's a subpath under /blog/ and no physical index.html exists on disk
            if (cleanPath !== '/blog' && !fs.existsSync(staticHtmlFile)) {
              const slug = cleanPath.replace('/blog/', '');
              req.url = '/blog/post/index.html?slug=' + encodeURIComponent(slug);
            }
          }
        }
        next();
      });
    },
    configurePreviewServer(server) {
      server.middlewares.use((req, res, next) => {
        if (req.url && req.url.startsWith('/blog/')) {
          const urlWithoutQuery = req.url.split('?')[0];
          if (!urlWithoutQuery.match(/\.(css|js|png|jpg|jpeg|gif|svg|ico|json|webmanifest|woff2?|ttf)$/i)) {
            const cleanPath = urlWithoutQuery.replace(/\/$/, '');
            const relPath = cleanPath.slice(1);
            const staticHtmlFile = path.resolve(__dirname, relPath, 'index.html');
            
            if (cleanPath !== '/blog' && !fs.existsSync(staticHtmlFile)) {
              const slug = cleanPath.replace('/blog/', '');
              req.url = '/blog/post/index.html?slug=' + encodeURIComponent(slug);
            }
          }
        }
        next();
      });
    }
  };
}

export default defineConfig(() => {
  return {
    plugins: [blogRewriterPlugin()],
    build: {
      rollupOptions: {
        input: {
          main: path.resolve(__dirname, 'index.html'),
          notFound: path.resolve(__dirname, '404.html'),
          gperyaLogin: path.resolve(__dirname, 'gperya-login/index.html'),
          gperyaRegister: path.resolve(__dirname, 'gperya-register/index.html'),
          gperyaCasino: path.resolve(__dirname, 'gperya-casino/index.html'),
          gperyaAppDownload: path.resolve(__dirname, 'gperya-app-download/index.html'),
          gperyaKycVerification: path.resolve(__dirname, 'gperya-kyc-verification/index.html'),
          gperyaRedemptionCode: path.resolve(__dirname, 'gperya-redemption-code/index.html'),
          gperyaWithdrawal: path.resolve(__dirname, 'gperya-withdrawal/index.html'),
          isGperyaLegit: path.resolve(__dirname, 'is-gperya-legit/index.html'),
          responsibleGaming: path.resolve(__dirname, 'responsible-gaming/index.html'),
          blog: path.resolve(__dirname, 'blog/index.html'),
          blogPost: path.resolve(__dirname, 'blog/post/index.html'),
          blogLoginNotWorking: path.resolve(__dirname, 'blog/gperya-login-not-working/index.html'),
          blogWithdrawalProblem: path.resolve(__dirname, 'blog/gperya-withdrawal-problem/index.html'),
          blogRedditReviews: path.resolve(__dirname, 'blog/gperya-reddit-reviews/index.html'),
          admin: path.resolve(__dirname, 'admin/index.html'),
          playnow: path.resolve(__dirname, 'playnow/index.html'),
          slots: path.resolve(__dirname, 'slots/index.html'),
          liveCasino: path.resolve(__dirname, 'live-casino/index.html'),
          fishing: path.resolve(__dirname, 'fishing/index.html'),
          peryaGames: path.resolve(__dirname, 'perya-games/index.html'),
          sports: path.resolve(__dirname, 'sports/index.html'),
        },
      },
    },
    server: {
      port: 3000,
      host: '0.0.0.0',
    },
  };
});
