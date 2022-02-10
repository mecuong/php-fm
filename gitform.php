<?php
    session_start();
    define('ROOT', str_replace('\\', '/', dirname(dirname(__DIR__))));
    define('GIT_BASE', 'master');
    $is_logged = $_SESSION['isLogin'] ?? false;

    // Save data with the json file data.json
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? $_GET['action'] ?? '';

        if ( $action == 'save') {
            $saveData = json_decode(file_get_contents('php://input'), true);
            file_put_contents('./data.json', json_encode($saveData));
        }

        if ($action == 'fileSave') {
            $saveData = json_decode(file_get_contents('php://input'), true);
            $fileName = $saveData['fileName'] ?? '';

            if (file_exists(ROOT . '/' . $fileName)) {
                @file_put_contents(ROOT . '/' . $fileName, $saveData['fileContent'] ?? '');
            }
            exit();
        }

        if ($action == 'login') {
            $mailList = [
                'nguyen.cuong@marketenterprise.vn',
                'vndeployer@marketenterprise.vn'
            ];

            $_SESSION['username'] = $_POST['username'] ?? '';
            $_SESSION['password'] = $_POST['password'] ?? '';
            if (in_array($_SESSION['username'], $mailList) && $_SESSION['password'] == 'oikura@2022') {
                $is_logged  = $_SESSION['isLogin'] = true;
            } else {
                $_SESSION['error'] = 'Login failed';
            }
        }
    }

    // Store data with the json file data.json
    if (file_exists('./data.json')) {
        $dataJSON = @file_get_contents('./data.json');
    } else {
        $dataJSON = '[]';
    }

    if (isset($_GET['file'])) {
        if (file_exists(ROOT . '/' . $_GET['file'])) {
            echo file_get_contents(ROOT . '/' . $_GET['file']);
        }

        exit();
    }

    if (isset($_GET['merge']) && $is_logged) {
        // Result run as server sent event
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');

        $data = json_decode($dataJSON, true);
        $issues = $data['issues'] ?? [];
        $output = '';

        sendMsg('START');

        execCommand('cd ' . ROOT . ' && git reset --hard && git fetch && git checkout ' . GIT_BASE . ' && git pull');

        // First step Merge all branches to this base
        $mergeOutput = execCommand('git merge --no-commit develop ' . join(' ', array_map(function($branch) {
            return 'origin/' . $branch;
        }, $issues)));

        $lineConflicts = array_filter($mergeOutput, function($line) {
            return strpos($line, 'ERROR: content conflict in') !== false
            || strpos($line, 'CONFLICT') !== false;
        });

        $conflictFiles = array_values(array_map(function($line) {
            preg_match('/[^\s]*$/', $line, $matches);
            return $matches[0] ?? '';
        }, $lineConflicts));

        $conflictFiles = array_filter($conflictFiles, function($file) {
            return file_exists(ROOT . '/' . $file);
        });

        if (count($conflictFiles) > 0) {
            sendMsg('CONFLICT:' . join(',', $conflictFiles));
        }

        sendMsg('END');
        exit;
    }

    if (isset($_GET['command']) && $is_logged) {
        // Result run as server sent event
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');

        $data = json_decode($dataJSON, true);
        $commandText = $data['command'] ?? '';

        sendMsg('START');

        array_map(function($line) {
            execCommand($line);
        }, explode('\r\n', $commandText));

        sendMsg('END');
        exit;
    }

    function execCommand(string $command)
    {
        $output = [];

        if (!preg_match('/2>/', $command)) {
            $command .= ' 2>&1';
        }

        exec($command, $output);

        array_map(function($line) {
            $line = trim($line);
            if ($line) {
                sendMsg($line);
            }
        }, $output);

        ob_flush();
        flush();

        return $output;
    }

    function sendMsg($msg)
    {
        echo "data: $msg\n\n";
        ob_flush();
        flush();
    }
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GIT FORM</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
        integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css"
        integrity="sha512-nMNlpuaDPrqlEls3IX/Q56H36qvBASwb3ipuo3MxeWbsQB1881ox0cRv7UPTgBlriqoynt35KjEwgGUeUXIPnw=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>

<body>
    <?php if (!$is_logged) { ?>
        <div class="container">
            <div class="row" style="height: 100vh;">
                <div class="col-12 col-lg-6 offset-lg-3 my-auto">
                    <div class="card">
                        <article class="card-body">
                        <h4 class="card-title mb-4 mt-1">Sign in</h4>
                        <hr>
                            <?php if (isset($_SESSION['error'])) { ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <strong>Loggin Faild</strong> Check your email and password
                                    <button type="button" onclick="this.parentNode.parentNode.removeChild(this.parentNode)" class="close" data-dismiss="alert" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                            <?php } ?>
                            <form method="POST">
                                <div class="form-group">
                                    <label>Your email</label>
                                    <input name="username" class="form-control" value="<?= $_SESSION['username'] ?? '' ?>" placeholder="Email" type="email">
                                </div>
                                <div class="form-group">
                                    <label>Your password</label>
                                    <input name="password" class="form-control" value="<?= $_SESSION['password'] ?? '' ?>" placeholder="******" type="password">
                                </div>
                                <div class="form-group">
                                    <button name="action" value="login" type="submit" class="btn btn-primary btn-block"> Login  </button>
                                </div>
                            </form>
                        </article>
                    </div>
                </div>
            </div>
        </div>
    <?php } else { ?>
    <div class="container">
        <form class="mt-5" method="POST" onsubmit="return saveList()">
            <fieldset id="list-issue">
                <legend>Issue List</legend>
            </fieldset>
            <div class="form-group row" >
                <div class="col-12"> <label>Run shell after merged</label></div>
                <div class="col-12" id="command" style="height: 200px"></div>
                <textarea name="command" id="command-input" class="form-control" style="display: none;"></textarea>
            </div>
            <div class="form-group row">
                <div class="btn-group col-12" role="group" aria-label="Basic example">
                    <button type="button" onclick="add()" class="btn btn-primary"><i class="fas fa-plus mr-1"></i>Add</button>
                    <button type="button" onclick="merge()" class="btn btn-large btn-warning"><i class="fas fa-sync mr-1"></i>Merge</button>
                    <button type="button" onclick="runCommand()" class="btn btn-large btn-danger"><i class="fas fa-play mr-1"></i>Run</button>
                </div>
            </div>
        </form>
        <div id="conflict-block" class="my-5 col-12 invisible">
            <h3>List file conflict</h3>
            <div id="conflict-files"></div>
        </div>
        <div class="col-12">
            <pre id="output"></pre>
        </div>
    </div>
    <script src="https://microsoft.github.io/monaco-editor/node_modules/monaco-editor/min/vs/loader.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"
        integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/ui/1.13.0/jquery-ui.min.js"
        integrity="sha256-hlKLmzaRlE8SCJC1Kw8zoUbU8BxA+8kR3gseuKfMjxA=" crossorigin="anonymous"></script>
    <script>
        var dataJSON = <?= $dataJSON ?> ?? {} ;
        var issues = dataJSON.issues || [];
        require.config({
            paths: {
                'vs': 'https://unpkg.com/monaco-editor@latest/min/vs'
            }
        });
        var proxy = URL.createObjectURL(new Blob([`
            self.MonacoEnvironment = {
                baseUrl: 'https://unpkg.com/monaco-editor@latest/min/'
            };
            importScripts('https://unpkg.com/monaco-editor@latest/min/vs/base/worker/workerMain.js');
        `], {
            type: 'text/javascript'
        }));
        window.MonacoEnvironment = {
            getWorkerUrl: () => proxy
        };
        require(['vs/editor/editor.main'], function () {
            window.editor = monaco.editor;
            window.commandEditor = window.editor.create(document.getElementById('command'), {
                value: dataJSON.command || '',
                language: 'shell',
                theme: 'vs-dark',
            });
        });

        for (var i = 0; i < issues.length; i++) {
            add(issues[i]);
        }

        function add(value = '') {
            var listForm = $('#list-issue');
            var content = `<div class="form-group row">
                    <div class="col-12">
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <div class="input-group-text"><i class="fa">#</i></div>
                            </div>
                            <input id="text" name="issues[]" type="text" value="${value}" class="form-control">
                            <div class="input-group-append" style="cursor:pointer" onclick="this.parentNode.parentNode.parentNode.parentNode.removeChild(this.parentNode.parentNode.parentNode)">
                                <div class="input-group-text"><i class="fa fa-minus"></i></div>
                            </div>
                        </div>
                    </div>
                </div>`;
            listForm.append(content);
        }

        function saveList() {
            // Post form value
            var issues = [];
            $('#list-issue input[name="issues[]"]').each(function () {
                issues.push($(this).val());
            });

            fetch('?action=save', {
                method: 'POST',
                body: JSON.stringify({
                    issues: issues,
                    command:  window.commandEditor.getValue()
                }),
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            return false;
        }

        async function merge() {
            await saveList();
            var button = $(event.target);
            var icon = button.find('i');
            icon.addClass('fa-spin');
            $('#output').text('Running ... \n');
            // Use server sent event
            var eventSource = new EventSource('?merge=1');
            eventSource.onmessage = function (e) {
                if (e.data === 'START') {
                } else if (e.data === 'END') {
                    icon.removeClass('fa-spin');
                    eventSource.close();
                } else if (e.data.includes('CONFLICT:')) {
                    haveConflict(e.data.split('CONFLICT:')[1].split(','));
                } else {
                    $('#output').append(e.data + '<br>');
                }
            };
        }

        function haveConflict(files) {
            $('#conflict-block').removeClass('invisible');
            var conflictFileListDom = $('#conflict-files');
            conflictFileListDom.html('');
            for (var i = 0; i < files.length; i++) {
                conflictFileListDom.append( `<div class="alert alert-danger">${files[i]}<i class="fas fa-pencil pull-right m-2" onclick="editFile('${files[i]}')"></i></div>`);
            }
        }

        function editFile(fileName) {
            var editorFileDom = $(event.target).parent();
            var editorId = btoa(fileName);
            $('.mo-editor').remove();
            editorFileDom.after(`<div id="${editorId}" class="mo-editor"></div>`);
            var language = fileName.split('.').pop();
            if (language == 'tpl') {
                language = 'html';
            }

            if (['vue', 'js', 'cjs', 'mjs'].includes(language)) {
                language = 'javascript';
            }

            fetch(`?file=${fileName}`)
                .then(function (response) {
                    return response.text();
                })
                .then(function (text) {
                    var editorDom = document.getElementById(editorId);
                    editorDom.style.height  = '500px';
                    editorDom.style['margin-top'] = '-1rem';
                    editorDom.style['margin-bottom'] = '1rem';
                    window.currentFile = fileName;
                    window.editor.create(editorDom, {
                        value: text,
                        language,
                        theme: 'vs-dark',
                    });
                });
        }

        function runCommand() {
            saveList();
            $('#conflict-files').html('');
            $('#conflict-block').addClass('invisible');
            $('#output').text('Running ... \n');
            // Use server sent event
            var eventSource = new EventSource('?command=true');
            eventSource.onmessage = function (e) {
                if (e.data === 'START') {
                } else if (e.data === 'END') {
                    eventSource.close();
                } else {
                    $('#output').append(e.data + '<br>');
                }
            };
        }

        document.onkeydown = function (e) {
            if (e.ctrlKey && e.keyCode === 83) {
                // Check empty conflict content
                var fileContent = window.editor.getModels()[0].getValue();

                if (!fileContent.includes('<<<<<<<') &&
                    !fileContent.includes('>>>>>>>') &&
                    !fileContent.includes('=======')) {
                    $('div.alert:contains("' + window.currentFile + '")').removeClass('alert-danger').addClass('alert-success');
                } else {
                    $('div.alert:contains("' + window.currentFile + '")').removeClass('alert-success').addClass('alert-danger');
                }

                fetch('?action=fileSave', {
                    method: 'POST',
                    body: JSON.stringify({
                        fileName: window.currentFile,
                        fileContent: window.editor.getModels()[0].getValue()
                    }),
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
            }
        };


    </script>
    <?php } ?>
</body>

</html>
