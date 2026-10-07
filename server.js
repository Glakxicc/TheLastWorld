const express = require("express");
const cors = require("cors");
const axios = require("axios");
require("dotenv").config();
 
const app = express();
 
app.use(cors());
app.use(express.json());
 
app.post("/api/formulaire", async (req, res) => {
try {
const webhookUrl = process.env.DISCORD_WEBHOOK_URL;
 
if (!webhookUrl) {
return res
.status(500)
.json({ error: "Webhook Discord introuvable" });
}
 
await axios.post(webhookUrl, req.body);
 
console.log("Formulaire envoyé à Discord");
 
res.status(200).json({
success: true,
message: "Formulaire envoyé",
});
} catch (err) {
console.error(err);
 
res.status(500).json({
success: false,
error: "Erreur lors de l'envoi",
});
}
});
 
const PORT = process.env.PORT || 3000;
 
app.listen(PORT, () => {
console.log(`Serveur démarré sur le port ${PORT}`);
});