package fr.thelastworld.stats;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import com.google.gson.JsonPrimitive;
import org.bukkit.Bukkit;
import org.bukkit.ChatColor;
import org.bukkit.OfflinePlayer;
import org.bukkit.Material;
import org.bukkit.Statistic;
import org.bukkit.enchantments.Enchantment;
import org.bukkit.entity.Player;
import org.bukkit.event.EventHandler;
import org.bukkit.event.EventPriority;
import org.bukkit.event.Listener;
import org.bukkit.event.player.AsyncPlayerPreLoginEvent;
import org.bukkit.event.player.PlayerJoinEvent;
import org.bukkit.event.player.PlayerQuitEvent;
import org.bukkit.inventory.ItemStack;
import org.bukkit.inventory.PlayerInventory;
import org.bukkit.inventory.meta.ItemMeta;
import org.bukkit.plugin.java.JavaPlugin;

import javax.net.ssl.SSLException;
import java.io.File;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.util.ArrayList;
import java.util.Collection;
import java.util.Collections;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Scanner;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.TimeUnit;
import java.util.logging.Level;
import java.util.regex.Pattern;

/**
 * Lien entre le serveur Minecraft et le site TheLastWorld :
 * - accès au serveur : seuls les comptes Minecraft liés à un joueur whitelisté sur le site peuvent entrer ;
 *   un compte non lié est refusé avec un code à saisir dans « Mon espace » (preuve qu'il en est le propriétaire) ;
 * - whitelist du serveur tenue à jour automatiquement depuis le site ;
 * - statistiques des joueurs (temps de jeu, morts, kills, dernière connexion) et joueurs connectés ;
 * - inventaire et coffre de l'Ender des joueurs, consultables par le staff sur le site.
 *
 * Prévu pour Mohist 1.12.2 (Forge + Bukkit, Java 8), fonctionne aussi sur Spigot/Paper 1.12.2+.
 */
public final class TLWStats extends JavaPlugin implements Listener {

    /** Pseudo Minecraft valide : écarte les faux joueurs des mods Forge ([Minecraft], [ComputerCraft]...). */
    private static final Pattern PLAYER_NAME = Pattern.compile("^[A-Za-z0-9_]{1,16}$");

    private URL statsEndpoint;
    private URL accessEndpoint;
    private String token;
    private Statistic playTime;

    private boolean accessControl;
    private boolean opsBypass;
    private boolean manageWhitelist;
    /** Comptes autorisés d'après le site ; gardés sur disque pour quand le site est injoignable. */
    private final Set<UUID> allowed = ConcurrentHashMap.newKeySet();
    private File allowedFile;

    @Override
    public void onEnable() {
        saveDefaultConfig();
        token = getConfig().getString("token", "").trim();
        String url = getConfig().getString("url", "").trim();
        String accessUrl = getConfig().getString("access-url", "").trim();

        if (token.isEmpty() || url.isEmpty()) {
            getLogger().warning("Renseignez url et token dans plugins/TLWStats/config.yml puis redémarrez le serveur.");
            return;
        }

        try {
            statsEndpoint = new URL(url);
            // Par défaut, access.php à côté de stats.php
            accessEndpoint = new URL(accessUrl.isEmpty() ? url.replaceFirst("[^/]*$", "access.php") : accessUrl);
        } catch (Exception error) {
            getLogger().warning("Adresse invalide dans config.yml : " + error.getMessage());
            return;
        }

        // PLAY_ONE_TICK en 1.12, renommée PLAY_ONE_MINUTE à partir de 1.13 (compte toujours des ticks)
        playTime = statistic("PLAY_ONE_TICK", "PLAY_ONE_MINUTE");
        getServer().getPluginManager().registerEvents(this, this);

        long statsTicks = Math.max(60, getConfig().getLong("interval-seconds", 300)) * 20L;
        Bukkit.getScheduler().runTaskTimer(this, () -> {
            List<Player> online = realPlayers(Bukkit.getOnlinePlayers(), null);
            sendStatsAsync(payload(online, uuids(online)));
        }, 20L * 10, statsTicks);

        setupAccessControl();
        getLogger().info("Connecté au site : " + url);
    }

    @Override
    public void onDisable() {
        if (statsEndpoint == null) {
            return;
        }
        // Arrêt du serveur : dernières statistiques, et tout le monde passe hors ligne.
        // Envoi bloquant car le plugin s'arrête juste après.
        try {
            post(statsEndpoint, payload(realPlayers(Bukkit.getOnlinePlayers(), null), Collections.<UUID>emptyList()));
        } catch (Exception error) {
            warnRequestFailure("Envoi des statistiques à l'arrêt impossible", error);
        }
    }

    // --- Accès au serveur ---

    private void setupAccessControl() {
        accessControl = getConfig().getBoolean("access-control.enabled", true);
        opsBypass = getConfig().getBoolean("access-control.ops-bypass", true);
        manageWhitelist = getConfig().getBoolean("access-control.manage-whitelist", true);
        if (!accessControl) {
            getLogger().warning("Contrôle d'accès désactivé : n'importe quel compte peut rejoindre le serveur.");
            return;
        }

        if (!Bukkit.getOnlineMode() && !behindProxy()) {
            getLogger().warning("Le serveur est en online-mode=false : les comptes Minecraft ne sont pas vérifiés par Mojang, "
                + "n'importe qui peut prendre le pseudo d'un autre. Passez online-mode=true dans server.properties.");
        }

        allowedFile = new File(getDataFolder(), "allowed-accounts.txt");
        loadAllowed();

        long syncTicks = Math.max(30, getConfig().getLong("access-control.sync-seconds", 60)) * 20L;
        Bukkit.getScheduler().runTaskTimerAsynchronously(this, this::syncAccess, 20L * 5, syncTicks);
    }

    /** Un compte se connecte : on demande au site s'il est lié à un joueur whitelisté. */
    @EventHandler(priority = EventPriority.LOWEST)
    public void onPreLogin(AsyncPlayerPreLoginEvent event) {
        if (!accessControl || accessEndpoint == null || event.getLoginResult() != AsyncPlayerPreLoginEvent.Result.ALLOWED) {
            return;
        }
        UUID uuid = event.getUniqueId();
        String name = event.getName();
        if (opsBypass && Bukkit.getOfflinePlayer(uuid).isOp()) {
            return;
        }

        JsonObject answer;
        try {
            JsonObject request = new JsonObject();
            request.addProperty("uuid", uuid.toString());
            request.addProperty("name", name);
            answer = new JsonParser().parse(post(accessEndpoint, request.toString())).getAsJsonObject();
        } catch (Exception error) {
            warnRequestFailure("Vérification du compte " + name + " impossible", error);
            // Site injoignable : on se fie à la dernière liste reçue
            if (!allowed.contains(uuid)) {
                event.disallow(AsyncPlayerPreLoginEvent.Result.KICK_OTHER, ChatColor.RED
                    + "Impossible de vérifier votre compte pour le moment.\n" + ChatColor.GRAY + "Réessayez dans quelques minutes.");
            }
            return;
        }

        if (answer.has("allowed") && answer.get("allowed").getAsBoolean()) {
            allowed.add(uuid);
            if (manageWhitelist && Bukkit.hasWhitelist()) {
                // La whitelist du serveur est vérifiée juste après : on y ajoute le joueur tout de suite
                whitelistNow(uuid);
            }
            return;
        }

        allowed.remove(uuid);
        event.disallow(AsyncPlayerPreLoginEvent.Result.KICK_WHITELIST, refusalMessage(answer));
        getLogger().info(name + " refusé : compte Minecraft " + (answer.has("code") ? "non lié au site." : "sans accès."));
    }

    private String refusalMessage(JsonObject answer) {
        String title = ChatColor.GOLD + "" + ChatColor.BOLD + "TheLastWorld\n\n";
        if (!answer.has("code")) {
            String message = answer.has("message") ? answer.get("message").getAsString() : "Accès refusé.";
            return title + ChatColor.RED + message;
        }
        String site = answer.has("site") ? answer.get("site").getAsString() : "le site";
        int minutes = answer.has("expiresInMinutes") ? answer.get("expiresInMinutes").getAsInt() : 10;
        return title
            + ChatColor.RED + "Votre compte Minecraft n'est pas encore lié au site.\n\n"
            + ChatColor.WHITE + "1. Connectez-vous sur " + ChatColor.YELLOW + site + ChatColor.WHITE + " > " + ChatColor.YELLOW + "Mon espace\n"
            + ChatColor.WHITE + "2. Entrez ce code : " + ChatColor.GREEN + ChatColor.BOLD + answer.get("code").getAsString() + "\n\n"
            + ChatColor.GRAY + "Code valable " + minutes + " minutes. Pas encore membre ? Candidatez sur le site.";
    }

    /** Liste des comptes autorisés : met à jour le cache, la whitelist du serveur, et expulse ceux qui ont perdu l'accès. */
    private void syncAccess() {
        Set<UUID> fresh = new HashSet<>();
        try {
            JsonObject answer = new JsonParser().parse(get(accessEndpoint)).getAsJsonObject();
            JsonArray list = answer.getAsJsonArray("allowed");
            if (list == null) {
                throw new IOException("réponse sans liste « allowed »");
            }
            for (JsonElement uuid : list) {
                fresh.add(UUID.fromString(uuid.getAsString()));
            }
        } catch (Exception error) {
            warnRequestFailure("Synchronisation des accès impossible", error);
            return;
        }

        allowed.retainAll(fresh);
        allowed.addAll(fresh);
        saveAllowed();

        Bukkit.getScheduler().runTask(this, () -> {
            if (manageWhitelist) {
                for (UUID uuid : fresh) {
                    OfflinePlayer account = Bukkit.getOfflinePlayer(uuid);
                    if (!account.isWhitelisted()) {
                        account.setWhitelisted(true);
                    }
                }
                for (OfflinePlayer account : new ArrayList<>(Bukkit.getWhitelistedPlayers())) {
                    if (!fresh.contains(account.getUniqueId()) && !(opsBypass && account.isOp())) {
                        account.setWhitelisted(false);
                    }
                }
            }
            for (Player player : realPlayers(Bukkit.getOnlinePlayers(), null)) {
                if (!fresh.contains(player.getUniqueId()) && !(opsBypass && player.isOp())) {
                    player.kickPlayer(ChatColor.RED + "Votre accès au serveur a été retiré.\n"
                        + ChatColor.GRAY + "Vérifiez votre compte Minecraft dans « Mon espace » sur le site.");
                    getLogger().info(player.getName() + " expulsé : n'a plus accès au serveur d'après le site.");
                }
            }
        });
    }

    /** Ajoute le joueur à la whitelist du serveur depuis le thread de connexion (attend le thread principal). */
    private void whitelistNow(UUID uuid) {
        try {
            Bukkit.getScheduler().callSyncMethod(this, () -> {
                Bukkit.getOfflinePlayer(uuid).setWhitelisted(true);
                return null;
            }).get(5, TimeUnit.SECONDS);
        } catch (Exception error) {
            getLogger().warning("Ajout à la whitelist impossible pour " + uuid + " : " + error);
        }
    }

    private void loadAllowed() {
        if (!allowedFile.isFile()) {
            return;
        }
        try {
            for (String line : Files.readAllLines(allowedFile.toPath(), StandardCharsets.UTF_8)) {
                if (!line.trim().isEmpty()) {
                    allowed.add(UUID.fromString(line.trim()));
                }
            }
        } catch (Exception error) {
            getLogger().warning("Lecture de " + allowedFile.getName() + " impossible : " + error);
        }
    }

    private void saveAllowed() {
        List<String> lines = new ArrayList<>();
        for (UUID uuid : allowed) {
            lines.add(uuid.toString());
        }
        try {
            Files.write(allowedFile.toPath(), lines, StandardCharsets.UTF_8);
        } catch (IOException error) {
            getLogger().warning("Écriture de " + allowedFile.getName() + " impossible : " + error);
        }
    }

    /** Derrière BungeeCord, online-mode=false est normal : c'est le proxy qui vérifie les comptes. */
    private static boolean behindProxy() {
        try {
            return Bukkit.spigot().getConfig().getBoolean("settings.bungeecord", false);
        } catch (Throwable error) {
            return false;
        }
    }

    // --- Statistiques ---

    @EventHandler(priority = EventPriority.MONITOR)
    public void onJoin(PlayerJoinEvent event) {
        if (!isRealPlayer(event.getPlayer())) {
            return;
        }
        sendStatsAsync(payload(Collections.singletonList(event.getPlayer()),
            uuids(realPlayers(Bukkit.getOnlinePlayers(), null))));
    }

    @EventHandler(priority = EventPriority.MONITOR)
    public void onQuit(PlayerQuitEvent event) {
        Player player = event.getPlayer();
        if (!isRealPlayer(player)) {
            return;
        }
        // Le joueur fait encore partie des connectés pendant cet événement : on l'exclut
        sendStatsAsync(payload(Collections.singletonList(player),
            uuids(realPlayers(Bukkit.getOnlinePlayers(), player.getUniqueId()))));
    }

    private void sendStatsAsync(String json) {
        Bukkit.getScheduler().runTaskAsynchronously(this, () -> {
            try {
                post(statsEndpoint, json);
            } catch (Exception error) {
                warnRequestFailure("Envoi des statistiques impossible", error);
            }
        });
    }

    /** Statistiques des joueurs donnés + liste complète des connectés. Lu sur le thread principal. */
    private String payload(Collection<? extends Player> players, Collection<UUID> online) {
        long now = System.currentTimeMillis();
        StringBuilder json = new StringBuilder("{\"players\":[");

        String separator = "";
        for (Player player : players) {
            json.append(separator)
                .append("{\"uuid\":\"").append(player.getUniqueId()).append('"')
                .append(",\"name\":\"").append(player.getName()).append('"')
                // Compte des ticks (20 par seconde)
                .append(",\"playtime\":").append(stat(player, playTime) / 20)
                .append(",\"deaths\":").append(stat(player, Statistic.DEATHS))
                .append(",\"mobKills\":").append(stat(player, Statistic.MOB_KILLS))
                .append(",\"playerKills\":").append(stat(player, Statistic.PLAYER_KILLS))
                .append(",\"lastSeen\":").append(now)
                .append(",\"inventory\":").append(inventory(player))
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

    // --- Inventaire ---

    /** Inventaire complet du joueur : rangées, armure, seconde main et coffre de l'Ender. */
    private JsonObject inventory(Player player) {
        JsonObject inventory = new JsonObject();
        try {
            PlayerInventory contents = player.getInventory();
            // 0-8 : barre d'action, 9-35 : inventaire
            inventory.add("main", items(contents.getStorageContents()));
            // 0 : bottes, 1 : jambières, 2 : plastron, 3 : casque
            inventory.add("armor", items(contents.getArmorContents()));
            inventory.add("offhand", items(new ItemStack[] {contents.getItemInOffHand()}));
            inventory.add("enderChest", items(player.getEnderChest().getContents()));
            inventory.addProperty("heldSlot", contents.getHeldItemSlot());
            inventory.addProperty("level", player.getLevel());
            inventory.addProperty("health", Math.round(player.getHealth() * 10) / 10.0);
            inventory.addProperty("food", player.getFoodLevel());
        } catch (Throwable error) {
            getLogger().fine("Inventaire illisible pour " + player.getName() + " : " + error);
        }
        return inventory;
    }

    /** Objets non vides d'une partie de l'inventaire, avec leur case. */
    private JsonArray items(ItemStack[] stacks) {
        JsonArray items = new JsonArray();
        for (int slot = 0; slot < stacks.length; slot++) {
            ItemStack stack = stacks[slot];
            if (stack == null || stack.getType() == Material.AIR || stack.getAmount() <= 0) {
                continue;
            }
            // Un objet moddé peut lever une erreur : on garde au moins son type
            JsonObject item = new JsonObject();
            item.addProperty("slot", slot);
            item.addProperty("type", stack.getType().name());
            item.addProperty("amount", stack.getAmount());
            try {
                item.addProperty("damage", stack.getDurability());
                item.addProperty("maxDamage", stack.getType().getMaxDurability());
                if (stack.hasItemMeta()) {
                    ItemMeta meta = stack.getItemMeta();
                    if (meta.hasDisplayName()) {
                        item.addProperty("name", ChatColor.stripColor(meta.getDisplayName()));
                    }
                    if (meta.hasLore()) {
                        JsonArray lore = new JsonArray();
                        for (String line : meta.getLore()) {
                            lore.add(new JsonPrimitive(ChatColor.stripColor(line)));
                        }
                        item.add("lore", lore);
                    }
                }
                if (!stack.getEnchantments().isEmpty()) {
                    JsonObject enchantments = new JsonObject();
                    for (Map.Entry<Enchantment, Integer> enchantment : stack.getEnchantments().entrySet()) {
                        enchantments.addProperty(enchantment.getKey().getName(), enchantment.getValue());
                    }
                    item.add("enchantments", enchantments);
                }
            } catch (Throwable error) {
                getLogger().fine("Objet " + stack.getType() + " illisible : " + error);
            }
            items.add(item);
        }
        return items;
    }

    /** Lecture protégée : sur un serveur hybride Forge, une statistique peut manquer ou lever une erreur. */
    private int stat(Player player, Statistic statistic) {
        if (statistic == null) {
            return 0;
        }
        try {
            return Math.max(0, player.getStatistic(statistic));
        } catch (Throwable error) {
            getLogger().fine("Statistique " + statistic + " illisible pour " + player.getName() + " : " + error);
            return 0;
        }
    }

    /** Première statistique existante parmi les noms donnés (les noms changent selon la version). */
    private static Statistic statistic(String... names) {
        for (String name : names) {
            try {
                return Statistic.valueOf(name);
            } catch (IllegalArgumentException ignored) {
                // Absente de cette version
            }
        }
        return null;
    }

    // --- Joueurs ---

    private static boolean isRealPlayer(Player player) {
        return player != null && player.getUniqueId() != null && player.getName() != null
            && PLAYER_NAME.matcher(player.getName()).matches();
    }

    private static List<Player> realPlayers(Collection<? extends Player> players, UUID except) {
        List<Player> result = new ArrayList<>();
        for (Player player : players) {
            if (isRealPlayer(player) && !player.getUniqueId().equals(except)) {
                result.add(player);
            }
        }
        return result;
    }

    private static List<UUID> uuids(Collection<Player> players) {
        List<UUID> result = new ArrayList<>();
        for (Player player : players) {
            result.add(player.getUniqueId());
        }
        return result;
    }

    // --- HTTP ---

    private String get(URL endpoint) throws IOException {
        return request(endpoint, "GET", null);
    }

    private String post(URL endpoint, String json) throws IOException {
        return request(endpoint, "POST", json);
    }

    /** Requête vers le site ; renvoie le corps de la réponse, ou lève une erreur si le site refuse. */
    private String request(URL endpoint, String method, String json) throws IOException {
        HttpURLConnection connection = (HttpURLConnection) endpoint.openConnection();
        try {
            connection.setConnectTimeout(5000);
            connection.setReadTimeout(10000);
            connection.setUseCaches(false);
            connection.setRequestMethod(method);
            connection.setRequestProperty("Accept", "application/json");
            // Sans User-Agent explicite, certains hébergeurs bloquent « Java/1.8.0_xxx »
            connection.setRequestProperty("User-Agent", "TLWStats/" + getDescription().getVersion());
            connection.setRequestProperty("X-TLW-Token", token);

            if (json != null) {
                byte[] body = json.getBytes(StandardCharsets.UTF_8);
                connection.setDoOutput(true);
                connection.setFixedLengthStreamingMode(body.length);
                connection.setRequestProperty("Content-Type", "application/json; charset=utf-8");
                try (OutputStream out = connection.getOutputStream()) {
                    out.write(body);
                }
            }

            int status = connection.getResponseCode();
            if (status != 200) {
                throw new IOException("le site a répondu " + status + " : " + read(connection.getErrorStream()));
            }
            return read(connection.getInputStream());
        } finally {
            connection.disconnect();
        }
    }

    private void warnRequestFailure(String message, Exception error) {
        if (error instanceof SSLException) {
            // Les vieilles versions de Java 8 (avant 8u101) ne reconnaissent pas les certificats Let's Encrypt
            getLogger().warning(message + " : connexion HTTPS refusée (" + error.getMessage() + "). "
                + "Mettez à jour Java 8 (8u101 minimum, idéalement la dernière 8u) sur le serveur.");
        } else if (error.getMessage() != null && error.getMessage().contains("répondu 401")) {
            getLogger().warning(message + " : clé refusée par le site. Le token de config.yml doit être "
                + "identique à minecraft.stats_token dans api/config.php.");
        } else {
            getLogger().log(Level.WARNING, message + " : " + error);
        }
    }

    private static String read(InputStream in) {
        if (in == null) {
            return "";
        }
        try (Scanner scanner = new Scanner(in, "UTF-8").useDelimiter("\\A")) {
            return scanner.hasNext() ? scanner.next() : "";
        }
    }
}
