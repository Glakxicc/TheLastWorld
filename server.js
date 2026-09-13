const express = require('express');
const app = express();
require('dotenv').config();

// Middleware pour autoriser les requêtes CORS (si ton frontend est sur un autre domaine/port)
app.use(require('cors')());

// Route pour récupérer l'URL du webhook
app.get('/api/webhook-url', (req, res) => {
  const webhookUrl = process.env.DISCORD_WEBHOOK_URL;
  if (!webhookUrl) {
    return res.status(500).json({ error: "URL de webhook non définie." });
  }
  res.json({ url: webhookUrl });
});

// Démarre le serveur
const PORT = process.env.PORT || 3000 || 80 || 8000 || 443;
app.listen(PORT, () => {
  console.log(`Serveur démarré sur http://localhost:${PORT}`);
});