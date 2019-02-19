pipeline {
    agent any

    stages {
        stage('Test') {
            steps {
                echo 'Setting build status...'
                githubNotify account: 'so-sure', context: 'CI-Web-Functional-Docker-MB', credentialsId: 'sosure-machine-token', description: '', gitApiUrl: '', repo: 'so-sure-web', sha: "${env.GIT_COMMIT}", status: 'PENDING', targetUrl: ''
                echo 'Running test...'
                awsCodeBuild artifactEncryptionDisabledOverride: '', artifactLocationOverride: '', artifactNameOverride: '', artifactNamespaceOverride: '', artifactPackagingOverride: '', artifactPathOverride: '', artifactTypeOverride: '', awsAccessKey: '', awsSecretKey: '', buildSpecFile: '', buildTimeoutOverride: '', cacheLocationOverride: '', cacheTypeOverride: '', certificateOverride: '', cloudWatchLogsGroupNameOverride: '', cloudWatchLogsStatusOverride: '', cloudWatchLogsStreamNameOverride: '', computeTypeOverride: '', credentialsId: 'jenkins-codebuilder', credentialsType: 'jenkins', envParameters: '', envVariables: '', environmentTypeOverride: '', gitCloneDepthOverride: '', imageOverride: '', insecureSslOverride: '', overrideArtifactName: '', privilegedModeOverride: '', projectName: 'Web', proxyHost: '', proxyPort: '', region: 'eu-west-1', reportBuildStatusOverride: '', s3LogsLocationOverride: '', s3LogsStatusOverride: '', serviceRoleOverride: '', sourceControlType: 'project', sourceLocationOverride: '', sourceTypeOverride: '', sourceVersion: '', sseAlgorithm: ''

            }
        }
    }
    post {
        success {
            githubNotify account: 'so-sure', context: 'CI-Web-Functional-Docker-MB', credentialsId: 'sosure-machine-token', description: '', gitApiUrl: '', repo: 'so-sure-web', sha: "${env.GIT_COMMIT}", status: 'SUCCESS', targetUrl: ''
        }
        failure {
            githubNotify account: 'so-sure', context: 'CI-Web-Functional-Docker-MB', credentialsId: 'sosure-machine-token', description: '', gitApiUrl: '', repo: 'so-sure-web', sha: "${env.GIT_COMMIT}", status: 'FAILURE', targetUrl: ''
        }
    }
}

