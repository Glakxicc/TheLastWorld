package fr.thelastworld.stats;

import org.bukkit.Bukkit;
import org.bukkit.Statistic;
import org.bukkit.entity.Player;
import org.bukkit.event.EventHandler;
import org.bukkit.event.Listener;
import org.bukkit.event.player.PlayerJoinEvent;
import org.bukkit.event.player.PlayerQuitEvent;
import org.bukkit.plugin.java.JavaPlugin;

import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.time.Duration;
import java.util.ArrayList;
import java.util.Collection;
import java.util.List;
import java.util.UUID;
import java.util.logging.Level;

/**
 * Envoie au site les statistiques des joueurs (temps de jeu, morts, kills, dernière connexion)
 * et la liste des joueurs connectés : à la connexion, à la déconnexion et à intervalle régulier.
 */
public final class TLWStats extends JavaPlugin implements Listener {

    private HttpClient http;
    private URI endpoint;
    private String token;

    @Override
    public void onEnable() {
        saveDefaultConfig();
        token = getConfig().getString("token", "").trim();
        String url = getConfig().getString("url", "").trim();

        if (token.isEmpty() || url.isEmpty()) {
            getLogger().warning("Renseignez url et token dans plugins/TLWStats/config.yml puis redémarrez le serveur.");
            return;
        }

        endpoint = URI.create(url);
        http = HttpClient.newBuilder().connectTimeout(Duration.ofSeconds(5)).build();
        getServer().getPluginManager().registerEvents(this, this);

        long intervalTicks = Math.max(60, getConfig().getLong("interval-seconds", 300)) * 20L;
        Bukkit.getScheduler().runTaskTimer(this, () -> {
            Collection<? extends Player> online = Bukkit.getOnlinePlayers();
            sendAsync(payload(online, uuids(online, null)));
        }, 20L * 10, intervalTicks);

        getLogger().info("Statistiques envoyées à " + url);
    }

    @Override
    public void onDisable() {
        if (http == null) {
            return;
        }
        // Arrêt du serveur : dernières statistiques, et tout le monde passe hors ligne.
        // Envoi bloquant car le plugin s'arrête juste après.
        try {
            http.send(request(payload(Bukkit.getOnlinePlayers(), List.of())), HttpResponse.BodyHandlers.discarding());
        } catch (Exception error) {
            getLogger().log(Level.WARNING, "Envoi des statistiques à l'arrêt impossible : " + error.getMessage());
        }
    }

    @EventHandler
    public void onJoin(PlayerJoinEvent event) {
        sendAsync(payload(List.of(event.getPlayer()), uuids(Bukkit.getOnlinePlayers(), null)));
    }

    @EventHandler
    public void onQuit(PlayerQuitEvent event) {
        // Le joueur fait encore partie des connectés pendant cet événement : on l'exclut
        Player player = event.getPlayer();
        sendAsync(payload(List.of(player), uuids(Bukkit.getOnlinePlayers(), player.getUniqueId())));
    }

    // --- Envoi ---

    private void sendAsync(String json) {
        http.sendAsync(request(json), HttpResponse.BodyHandlers.ofString())
            .thenAccept(response -> {
                if (response.statusCode() != 200) {
                    getLogger().warning("Le site a refusé les statistiques (" + response.statusCode() + ") : " + response.body());
                }
            })
            .exceptionally(error -> {
                getLogger().warning("Site injoignable : " + error.getMessage());
                return null;
            });
    }

    private HttpRequest request(String json) {
        return HttpRequest.newBuilder(endpoint)
            .timeout(Duration.ofSeconds(10))
            .header("Content-Type", "application/json")
            .header("X-TLW-Token", token)
            .POST(HttpRequest.BodyPublishers.ofString(json))
            .build();
    }

    // --- JSON ---

    /** Statistiques des joueurs donnés + liste complète des connectés. Lu sur le thread principal. */
    private String payload(Collection<? extends Player> players, Collection<UUID> online) {
        long now = System.currentTimeMillis();
        StringBuilder json = new StringBuilder("{\"players\":[");

        String separator = "";
        for (Player player : players) {
            json.append(separator)
                .append("{\"uuid\":\"").append(player.getUniqueId()).append('"')
                .append(",\"name\":\"").append(escape(player.getName())).append('"')
                // PLAY_ONE_MINUTE compte en réalité des ticks (20 par seconde)
                .append(",\"playtime\":").append(player.getStatistic(Statistic.PLAY_ONE_MINUTE) / 20)
                .append(",\"deaths\":").append(player.getStatistic(Statistic.DEATHS))
                .append(",\"mobKills\":").append(player.getStatistic(Statistic.MOB_KILLS))
                .append(",\"playerKills\":").append(player.getStatistic(Statistic.PLAYER_KILLS))
                .append(",\"lastSeen\":").append(now)
                .append('}');
            separator = ",";
        }

        json.append("],\"online\":[");
        separator = "";
        for (UUID uuid : online) {
            json.append(separator).append('"').append(uuid).append('"');
            separator = ",";
        }
        return json.append("]}").toString();
    }

    private static List<UUID> uuids(Collection<? extends Player> players, UUID except) {
        List<UUID> result = new ArrayList<>();
        for (Player player : players) {
            if (!player.getUniqueId().equals(except)) {
                result.add(player.getUniqueId());
            }
        }
        return result;
    }

    private static String escape(String value) {
        return value.replace("\\", "\\\\").replace("\"", "\\\"");
    }
}
