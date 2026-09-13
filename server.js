const express = require('express');
const app = express();
require('dotenv').config();

// Middleware pour autoriser les requêtes CORS (si ton frontend est sur un autre domaine)
app.use((req, res, next) => {
  res.header('Access-Control-Allow-Origin', '*');
  next();
});

// Route pour récupérer l'URL du webhook
app.get('/api/webhook-url', (req, res) => {
  res.json({ url: process.env.DISCORD_WEBHOOK_URL });
});

app.listen(3000, () => {
  console.log('Serveur démarré sur http://localhost:3000');
});