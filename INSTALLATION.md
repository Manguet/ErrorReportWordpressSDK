# Guide d'Installation - Error Explorer WordPress Plugin

## Méthode 1: Installation via Archive ZIP (Recommandée)

### Étape 1: Télécharger le Plugin
- Téléchargez le fichier `error-explorer-wordpress-plugin.zip` depuis votre serveur Error Explorer

### Étape 2: Installation via WordPress Admin
1. Connectez-vous à votre administration WordPress
2. Allez dans **Extensions** → **Ajouter**
3. Cliquez sur **Téléverser une extension**
4. Sélectionnez le fichier `error-explorer-wordpress-plugin.zip`
5. Cliquez sur **Installer maintenant**
6. Une fois installé, cliquez sur **Activer l'extension**

### Étape 3: Configuration
1. Allez dans **Réglages** → **Error Explorer**
2. Configurez les paramètres :
   - ✅ **Activer le rapport d'erreurs**
   - 🔗 **URL Webhook** : Copiez l'URL depuis votre projet Error Explorer
   - ⚙️ **Options de capture** : Configurez selon vos besoins

### Étape 4: Test
1. Cliquez sur **"Send Test Error"** dans les réglages
2. Vérifiez que l'erreur apparaît dans votre dashboard Error Explorer

---

## Méthode 2: Installation Manuelle (FTP)

### Étape 1: Préparation
```bash
# Extraire l'archive
unzip error-explorer-wordpress-plugin.zip
```

### Étape 2: Upload via FTP
1. Uploadez le dossier `error-explorer/` dans `/wp-content/plugins/`
2. Vérifiez que la structure est : `/wp-content/plugins/error-explorer/error-explorer.php`

### Étape 3: Activation
1. Dans WordPress Admin → **Extensions**
2. Trouvez **"Error Explorer Reporter"**
3. Cliquez sur **Activer**

---

## Méthode 3: Installation via Composer (Développeurs)

### Étape 1: Ajouter le Repository
```bash
composer config repositories.error-explorer-wp path ../packages/wordpress-error-reporter
```

### Étape 2: Installation
```bash
composer require error-explorer/wordpress-error-reporter
```

### Étape 3: Intégration dans functions.php
```php
// Dans votre thème functions.php ou dans un plugin
add_action('plugins_loaded', function() {
    if (class_exists('ErrorExplorer\WordPressErrorReporter\ErrorReporter')) {
        $webhook_url = 'VOTRE_URL_WEBHOOK_ICI';
        
        $errorReporter = new \ErrorExplorer\WordPressErrorReporter\ErrorReporter($webhook_url, [
            'environment' => wp_get_environment_type(),
            'project_name' => get_bloginfo('name'),
            'capture_request_data' => true,
            'capture_session_data' => true,
            'capture_server_data' => true,
        ]);
        
        $errorReporter->register();
    }
});
```

---

## Configuration Avancée

### Variables d'Environnement
Vous pouvez configurer via des constantes dans `wp-config.php` :

```php
// wp-config.php
define('ERROR_EXPLORER_WEBHOOK_URL', 'https://votre-domaine.com/webhook/error/votre-token');
define('ERROR_EXPLORER_ENABLED', true);
define('ERROR_EXPLORER_ENVIRONMENT', 'production');
```

### Configuration Programmatique
```php
// Pour un contrôle total
add_action('init', function() {
    if (class_exists('ErrorExplorer\WordPressErrorReporter\ErrorReporter')) {
        $config = [
            'environment' => WP_ENVIRONMENT_TYPE ?: 'production',
            'project_name' => get_bloginfo('name'),
            'capture_request_data' => !is_admin(), // Pas d'admin
            'capture_session_data' => is_user_logged_in(),
            'capture_server_data' => true,
            'max_breadcrumbs' => 15,
        ];
        
        $errorReporter = new \ErrorExplorer\WordPressErrorReporter\ErrorReporter(
            ERROR_EXPLORER_WEBHOOK_URL,
            $config
        );
        
        $errorReporter->register();
        
        // Ajouter des breadcrumbs spécifiques
        $errorReporter->addBreadcrumb('WordPress initialized', 'system', 'info');
    }
});
```

---

## Intégrations Spécifiques

### WooCommerce
```php
// Tracker les erreurs WooCommerce
add_action('woocommerce_order_status_failed', function($order_id) {
    global $errorReporter;
    if ($errorReporter) {
        $errorReporter->addBreadcrumb('Order failed', 'woocommerce', 'error', [
            'order_id' => $order_id
        ]);
    }
});
```

### Contact Form 7
```php
// Tracker les erreurs de formulaires
add_action('wpcf7_mail_failed', function($contact_form) {
    global $errorReporter;
    if ($errorReporter) {
        $errorReporter->reportMessage(
            'Contact Form 7 mail failed',
            wp_get_environment_type(),
            null,
            'error',
            ['form_id' => $contact_form->id()]
        );
    }
});
```

---

## Vérification de l'Installation

### 1. Vérifier les Fichiers
- ✅ `/wp-content/plugins/error-explorer/error-explorer.php` existe
- ✅ `/wp-content/plugins/error-explorer/vendor/` contient les dépendances
- ✅ `/wp-content/plugins/error-explorer/src/` contient les classes PHP

### 2. Vérifier l'Activation
- ✅ Plugin visible dans **Extensions**
- ✅ Menu **Error Explorer** dans **Réglages**
- ✅ Pas d'erreurs PHP dans les logs

### 3. Test de Fonctionnement
```php
// Test rapide en ajoutant dans functions.php temporairement
add_action('init', function() {
    if (current_user_can('administrator')) {
        throw new Exception('Test Error Explorer - ' . date('Y-m-d H:i:s'));
    }
});
```

---

## Dépannage

### Plugin ne s'active pas
- Vérifiez PHP ≥ 7.4
- Vérifiez que toutes les dépendances sont présentes dans `/vendor/`
- Regardez les logs d'erreur WordPress

### Erreurs non envoyées
- Vérifiez l'URL webhook dans les réglages
- Testez avec le bouton "Send Test Error"
- Vérifiez les logs d'erreur pour des messages de connexion

### Performances
```php
// Limiter l'envoi d'erreurs en production
add_filter('error_explorer_should_report', function($should_report, $exception) {
    // Ne pas reporter les erreurs de notice en production
    if (wp_get_environment_type() === 'production' && 
        $exception instanceof ErrorException && 
        $exception->getSeverity() === E_NOTICE) {
        return false;
    }
    return $should_report;
}, 10, 2);
```

---

## Support

- 📖 **Documentation** : README.md
- 🐛 **Issues** : Créez un ticket dans votre projet Error Explorer
- 📧 **Contact** : error.explorer.contact@gmail.com
