<?php

session_start();

include "config.inc.php";
include "spotify.class.php";

function redirect($uri = "")
{
    header("Location: " . $uri);
    exit;
}

/* Logout functionality */
if (isset($_GET['page']) && $_GET['page'] === "logout") {
    $map = array(
        "state",
        "state_valid",
        "access_token",
        "expires_in",
        "refresh_token",
        //"error",
    );
    foreach ($map as $item) {
        unset($_SESSION[$item]);
    }
    redirect(APP_URL);
}

/* Regenerating "state" phrase if needed (300 seconds lifetime) */
if (!isset($_SESSION['state']) || empty($_SESSION['state']) || (isset($_SESSION['state_valid']) && intval($_SESSION['state_valid']) > (time() + 300))) {
    $_SESSION['state'] = substr(md5(uniqid()), 0, 12);
    $_SESSION['state_valid'] = time();
}

/* API Class initialization */
$api = new Spotify(array(
    "client_id" => CLIENT_ID,
    "client_secret" => CLIENT_SECRET,
    "redirect_uri" => APP_URL,
    "state" => $_SESSION['state'],
    "scopes" => array(
        "user-library-read",
        "user-library-modify",
        "user-read-email",
        "user-read-private"
    )
));

/* Pages */
if (isset($_SESSION['access_token']) && !empty($_SESSION['access_token'])) {
    $api->set_access_token($_SESSION['access_token']);

    if (time() > $_SESSION['expires_in'] && isset($_SESSION['refresh_token']) && $_SESSION['refresh_token']) {
        $tokens = $api->refresh_token($_SESSION['refresh_token']);

        if ($tokens && isset($tokens->access_token)) {
            $_SESSION['access_token'] = $tokens->access_token;
            $_SESSION['expires_in'] = time() + (int)$tokens->expires_in;
            unset($_SESSION['refresh_token']);
            redirect(APP_URL);
        } elseif (isset($tokens->error)) {
            $_SESSION['error'] = $tokens->error_description;
            redirect(APP_URL . "?" . http_build_query(array("page" => "logout")));
        }
    } elseif (time() > $_SESSION['expires_in']) {
        $_SESSION['error'] = "Session outdated. Please log in again.";
        redirect(APP_URL . "?" . http_build_query(array("page" => "logout")));
    }

    if (!isset($_GET['page']) || empty($_GET['page'])) {
        $me = $api->get("get", "/me");
        if (isset($tokens->error)) {
            $_SESSION['error'] = $tokens->error_description;
            redirect(APP_URL . "?" . http_build_query(array("page" => "logout")));
        }
    }

    /* Tracks Page */
    if (isset($_GET['page']) && $_GET['page'] === "browse") {
        $current_offset = isset($_GET['offset']) ? $_GET['offset'] : 0;
        $limit = 50;

        $tracks = $api->get("get", "/me/tracks", array(
            "offset" => $current_offset,
            "limit" => $limit
        ));

        $pages = ceil($tracks->total / $tracks->limit);
    }

    /* Export Page */
    if (isset($_GET['page']) && $_GET['page'] === "export") {
        $offset = 0;
        $total = 0;
        $loaded = 0;
        $ids = array();

        $tracks = $api->get("get", "/me/tracks", array(
            "offset" => $offset,
            "limit" => 50
        ));

        if ($tracks && isset($tracks->items)) {
            foreach ($tracks->items as $track) {
                $ids[] = $track->track->id;
                $loaded++;
            }

            $total = $tracks->total;
            $pages = ceil($tracks->total / $tracks->limit);
            if ($pages > 1) {
                for ($p = 1; $p <= $pages; $p++) {
                    //sleep(1);

                    $offset = $p * $tracks->limit;

                    $tracks = $api->get("get", "/me/tracks", array(
                        "offset" => $offset,
                        "limit" => 50
                    ));

                    if ($tracks && isset($tracks->items)) {
                        foreach ($tracks->items as $track) {
                            $ids[] = $track->track->id;
                            $loaded++;
                        }
                    } elseif (isset($tracks->error)) {
                        $_SESSION['error'] = $tracks->error_descriotion;
                    } else {
                        $_SESSION['error'] = "Unknown error";
                    }
                }
            }
        } elseif (isset($tracks->error)) {
            $_SESSION['error'] = $tracks->error_descriotion;
        } else {
            $_SESSION['error'] = "Unknown error";
        }
    }

    /* Import Page */
    if (isset($_GET['page']) && $_GET['page'] === "do_import" && isset($_POST['list'])) {
        $list = explode("\n", $_POST['list']);
        if (count($list) === 0) {
            $_SESSION['error'] = "Invalid list";
            redirect(APP_URL);
        }

        $valid_ids = array();
        $invalid_ids = array();
        foreach ($list as $track) {
            if (preg_match("/[A-Za-z0-9]{1,22}/siu", $track)) {
                $valid_ids[] = trim($track);
            } else {
                $invalid_ids[] = trim($track);
            }
        }

        if (count($valid_ids) > 0) {
            $success = 0;
            $fail = 0;
            $pages = ceil(count($valid_ids) / 50);
            for ($p = 0; $p < $pages; $p++) {
                $current_ids = array_slice($valid_ids, $p * 50, 50);
                $response = $api->get("PUT", "/me/tracks", json_encode(array("ids" => $current_ids)), array("Content-type: application/json"));
                if (!isset($response->error) || is_null($response) || $api->get_http_code() === 200)
                    $success += count($current_ids);
                else
                    $fail += count($current_ids);
            }
        }
    }

} elseif (isset($_GET['code']) && !empty($_GET['code']) && isset($_GET['state']) && $_GET['state'] === $_SESSION['state']) {
    $tokens = $api->get_token($_GET['code']);

    if ($tokens && isset($tokens->access_token)) {
        $_SESSION['access_token'] = $tokens->access_token;
        $_SESSION['expires_in'] = time() + (int)$tokens->expires_in;
        $_SESSION['refresh_token'] = $tokens->refresh_token;
        redirect(APP_URL);
    } elseif (isset($tokens->error)) {
        $_SESSION['error'] = $tokens->error_description;
        redirect(APP_URL . "?" . http_build_query(array("page" => "logout")));
    }
} else {
    $login_url = $api->get_auth_url();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <title>Spotify Import/Export</title>

    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css"
          integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">

    <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js"
            integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo"
            crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.3/umd/popper.min.js"
            integrity="sha384-ZMP7rVo3mIykV+2+9J3UJ46jBk0WLaUAdn689aCwoqbBJiSnjAK/l8WvCWPIPm49"
            crossorigin="anonymous"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/js/bootstrap.min.js"
            integrity="sha384-ChfqqxuZUCnJSK3+MXmPNIyE6ZbWh2IMqE241rYiqJxyMiZ6OW/JmZQ5stwEULTy"
            crossorigin="anonymous"></script>
</head>
<body>
<div class="container-fluid">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <a class="navbar-brand" href="<?php echo APP_URL; ?>">Spotify Import/Export</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbar"
                aria-controls="navbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbar">
            <ul class="navbar-nav">
                <li class="nav-item active">
                    <a class="nav-link" href="<?php echo APP_URL; ?>">Home <span class="sr-only">(current)</span></a>
                </li>
                <?php if (isset($_SESSION['access_token']) && !empty($_SESSION['access_token'])) { ?>
                    <li class="nav-item">
                        <a class="nav-link"
                           href="<?php echo APP_URL . "?" . http_build_query(array("page" => "browse")); ?>">Browse</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link"
                           href="<?php echo APP_URL . "?" . http_build_query(array("page" => "export")); ?>">Export
                            All</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link"
                           href="<?php echo APP_URL . "?" . http_build_query(array("page" => "import")); ?>">Import</a>
                    </li>
                <?php } ?>
                <?php if (isset($login_url)) { ?>
                    <li class="nav-item">
                        <a class="nav-link"
                           href="<?php echo $login_url; ?>">Login</a>
                    </li>
                <?php } else { ?>
                    <li class="nav-item">
                        <a class="nav-link"
                           href="<?php echo APP_URL . "?" . http_build_query(array("page" => "logout")); ?>">Logout</a>
                    </li>
                <?php } ?>
            </ul>
        </div>
    </nav>

    <?php if (isset($_SESSION['error']) && !empty($_SESSION['error'])) { ?>
        <div class="alert alert-danger">
            <?php echo $_SESSION['error']; ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php } ?>

    <?php if (isset($_SESSION['access_token']) && !empty($_SESSION['access_token'])) { ?>
        <?php if (!isset($_GET['page']) || empty($_GET['page'])) { ?>
            <div class="row mt-3">
                <div class="col-6">
                    <div class="card">
                        <div class="card-body text-center">
                            <a href="<?php echo $me->external_urls->spotify; ?>" target="_blank">
                                <img src="<?php echo isset($me->images) && is_array($me->images) && count($me->images) > 0 ? $me->images[0]->url : ""; ?>"
                                     class="img-responsive"
                                     alt="No user images"/>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="card">
                        <div class="card-header">
                            Home
                        </div>
                        <div class="card-body">
                            <b>
                                Welcome,
                            </b>
                            <a href="<?php echo $me->external_urls->spotify; ?>" target="_blank">
                                <?php echo $me->id; ?>
                            </a>
                            &lt;<?php echo $me->email; ?>&gt;
                        </div>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item">
                                <a class="nav-link"
                                   href="<?php echo APP_URL . "?" . http_build_query(array("page" => "browse")); ?>">
                                    Browse Your Library
                                </a>
                            </li>
                            <li class="list-group-item">
                                <a class="nav-link"
                                   href="<?php echo APP_URL . "?" . http_build_query(array("page" => "export")); ?>">
                                    Export Whole Library IDs
                                </a>
                            </li>
                            <li class="list-group-item">
                                <a class="nav-link"
                                   href="<?php echo APP_URL . "?" . http_build_query(array("page" => "import")); ?>">
                                    Import IDs to Account Songs Library
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        <?php } ?>

        <?php if (isset($_GET['page']) && $_GET['page'] === "browse") { ?>
            <div class="row mt-3">
                <div class="col-12">
                    <?php if ($tracks && isset($tracks->total)) { ?>
                        <nav aria-label="Navigation">
                            <ul class="pagination">
                                <?php for ($i = 1; $i <= $pages; $i++) { ?>
                                    <?php

                                    $offset = $tracks->limit * ($i - 1);

                                    ?>
                                    <li class="page-item <?php echo (int)$offset === (int)$current_offset ? "active" : "" ?>">
                                        <a class="page-link"
                                           href="<?php echo APP_URL . '?' . http_build_query(array("page" => "browse", "offset" => $offset)); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php } ?>
                            </ul>
                        </nav>
                    <?php } ?>
                    <div class="mt-2 mb-2">
                        <button onclick="list_modal();" class="btn btn-info">
                            Export Selected
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead class="thead-dark">
                            <tr>
                                <th scope="col">
                                    <input type="checkbox" id="select-all" title="Select All Tracks"/>
                                </th>
                                <th scope="col">
                                    ID
                                </th>
                                <th scope="col">
                                    Artist
                                </th>
                                <th scope="col">
                                    Title
                                </th>
                                <th scope="col">
                                    Album
                                </th>
                                <th scope="col">
                                    Duration
                                </th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($tracks && isset($tracks->items) && isset($tracks->total) && $tracks->total > 0) { ?>
                                <?php foreach ($tracks->items as $track) { ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" id="track_<?php echo $track->track->id; ?>"
                                                   class="track-checkbox"
                                                   value="<?php echo $track->track->id; ?>"/>
                                        </td>
                                        <td>
                                            <?php echo $track->track->id; ?>
                                        </td>
                                        <td>
                                            <?php if (count($track->track->artists) > 0) { ?>
                                                <?php $artists_names = array(); ?>
                                                <?php foreach ($track->track->artists as $artist) {
                                                    $artists_names[] = $artist->name;
                                                } ?>
                                                <?php echo implode(", ", $artists_names); ?>
                                            <?php } else { ?>
                                                Unknown Artist
                                            <?php } ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo $track->track->external_urls->spotify; ?>"
                                               target="_blank">
                                                <?php echo $track->track->name; ?>
                                            </a>
                                        </td>
                                        <td>
                                            <a href="<?php echo $track->track->album->external_urls->spotify; ?>"
                                               target="_blank">
                                                <?php echo $track->track->album->name; ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php

                                            $milliseconds = $track->track->duration_ms;
                                            $hours = floor($track->track->duration_ms / 3600000);
                                            $milliseconds -= $hours * 3600000;
                                            $minutes = floor($milliseconds / 60000);
                                            $milliseconds -= $minutes * 60000;
                                            $seconds = ceil($milliseconds / 1000);

                                            ?>
                                            <?php echo ($hours > 0 ? sprintf("%02d", $hours) . ":" : "") .
                                                sprintf("%02d", $minutes) . ":" .
                                                sprintf("%02d", $seconds); ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php } elseif ($tracks && isset($tracks->items) && isset($tracks->total) && $tracks->total > 0) { ?>
                                <tr>
                                    <td colspan="6">
                                        Your library empty.
                                    </td>
                                </tr>
                            <?php } elseif ($tracks && isset($tracks->error)) { ?>
                                <tr>
                                    <td colspan="6">
                                        <?php echo $tracks->error_description; ?>
                                    </td>
                                </tr>
                            <?php } else { ?>
                                <tr>
                                    <td colspan="6">
                                        Unknown error
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                        <div class="mt-2 mb-2">
                            <button onclick="list_modal();" class="btn btn-info">
                                Export Selected
                            </button>
                        </div>
                        <div class="modal" tabindex="-1" role="dialog" id="list-modal">
                            <div class="modal-dialog" role="document">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Exported List</h5>
                                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                            <span aria-hidden="true">&times;</span>
                                        </button>
                                    </div>
                                    <div class="modal-body">
                                        <h3>
                                            Copy text from field below, save to somewhere and use while importing:
                                        </h3>
                                        <textarea title="Spotify songs IDs" placeholder="Spotify songs IDs"
                                                  id="list-modal-textarea"
                                                  class="form-control"
                                                  rows="20"></textarea>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">
                                            Close
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <script>
                            $(function () {
                                $('#select-all').change(function () {
                                    $('.track-checkbox').prop('checked', $('#select-all').prop('checked'));
                                });
                            });

                            function list_modal() {
                                var list = [];
                                $('#list-modal-textarea').val('');
                                $('.track-checkbox:checked').each(function (i, cb) {
                                    list.push($(cb).val());
                                });
                                $('#list-modal-textarea').val(list.join("\n"));
                                $('#list-modal').modal('show')
                            }
                        </script>
                    </div>
                    <?php if ($tracks && isset($tracks->total)) { ?>
                        <nav aria-label="Navigation">
                            <ul class="pagination">
                                <?php for ($i = 1; $i <= $pages; $i++) { ?>
                                    <?php

                                    $offset = $tracks->limit * ($i - 1);

                                    ?>
                                    <li class="page-item <?php echo (int)$offset === (int)$current_offset ? "active" : "" ?>">
                                        <a class="page-link"
                                           href="<?php echo APP_URL . '?' . http_build_query(array("page" => "browse", "offset" => $offset)); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php } ?>
                            </ul>
                        </nav>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>

        <?php if (isset($_GET['page']) && $_GET['page'] === "export") { ?>
            <div class="row mt-3">
                <div class="col-12">
                    <div class="alert alert-<?php echo (int)$loaded === (int)$total ? "success" : "danger"; ?>">
                        Loaded <?php echo $loaded; ?> of <?php echo $total; ?> tracks
                    </div>
                    <h3>
                        Copy text from field below, save to somewhere and use while importing:
                    </h3>
                    <textarea title="Spotify songs IDs" placeholder="Spotify songs IDs" class="form-control"
                              rows="20"><?php echo implode("\n", $ids); ?></textarea>
                </div>
            </div>
        <?php } ?>

        <?php if (isset($_GET['page']) && $_GET['page'] === "import") { ?>
            <div class="row mt-3">
                <div class="col-12">
                    <h3>Paste exported list with songs IDs here:</h3>
                    <form action="<?php echo APP_URL . '?' . http_build_query(array("page" => "do_import")); ?>"
                          method="POST">
                        <div class="form-group">
                            <textarea title="Spotify songs IDs" placeholder="Spotify songs IDs" class="form-control"
                                      name="list" required
                                      rows="20"></textarea>
                        </div>
                        <input type="submit" value="-> and now click this button <-" class="btn btn-primary"/>
                    </form>
                </div>
            </div>
        <?php } ?>

        <?php if (isset($_GET['page']) && $_GET['page'] === "do_import") { ?>
            <div class="row mt-3">
                <div class="col-12">
                    <?php if (isset($valid_ids) && count($valid_ids) > 0) { ?>
                        <div class="alert alert-success">
                            Found <?php echo count($valid_ids); ?> valid tracks IDs.
                        </div>
                    <?php } ?>
                    <?php if (isset($invalid_ids) && count($invalid_ids) > 0) { ?>
                        <div class="alert alert-danger">
                            Found <?php echo count($invalid_ids); ?> invalid tracks IDs.
                        </div>
                    <?php } ?>
                    <?php if (isset($success) && $success > 0) { ?>
                        <div class="alert alert-success">
                            Successfully imported <?php echo $success; ?> tracks.
                        </div>
                    <?php } ?>
                    <?php if (isset($fail) && $fail > 0) { ?>
                        <div class="alert alert-danger">
                            Not imported <?php echo $fail; ?> tracks.
                        </div>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
    <?php } else { ?>
        <div class="row mt-3">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        Info
                    </div>
                    <div class="card-body">
                        A simple tool for importing/exporting tracks from Spotify Library.
                    </div>
                    <div class="card-footer text-center">
                        <a href="<?php echo $login_url; ?>" class="btn btn-info">Try it NOW!</a>
                    </div>
                </div>
            </div>
        </div>
    <?php } ?>
</div>
<?php if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'footer.php')) include __DIR__ . DIRECTORY_SEPARATOR . 'footer.php'; ?>
</body>
</html>