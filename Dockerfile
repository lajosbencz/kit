FROM alpine:3.18

ARG GITHUB_USER=lajosbencz
ARG USER=git
ARG GROUP=git
ARG PASSWORD="12345"
ARG PATH_KIT=/var/kit
ARG PATH_CERTS=${PATH_KIT}/certs
ARG PATH_SSH=${PATH_KIT}/ssh
ARG PATH_MOUNT=${PATH_KIT}/mount
ARG PATH_REPO=${PATH_MOUNT}/kit.git

ENV KUBECONFIG = ${PATH_KIT}/kubeconfig

RUN set -ex; \
    apk add --no-cache \
        openssl \
        php82 \
        multirun \
        curl \
        git \
        openssh; \
    ln -s /usr/bin/php82 /usr/bin/php; \
    rm /etc/motd

WORKDIR /root

RUN set -ex; \
    curl -LO "https://dl.k8s.io/release/$(curl -L -s https://dl.k8s.io/release/stable.txt)/bin/linux/amd64/kubectl"; \
    chmod +x kubectl; \
    mv kubectl /usr/local/bin/; \
    curl -sL https://raw.githubusercontent.com/helm/helm/main/scripts/get-helm-3 | sh

RUN set -ex; \
    addgroup "${GROUP}"; \
    adduser \
        --gecos "Git User" \
        --ingroup "${GROUP}" \
        --home "${PATH_MOUNT}" \
        --disabled-password \
        --shell "$(which git-shell)" \
        "${USER}" ; \
    echo "${USER}:${PASSWORD}" | chpasswd; \
    echo -e "\
PasswordAuthentication no\n\
Match user git\n\
   AuthorizedKeysFile ${PATH_SSH}/authorized_keys\n\
" \
    >> /etc/ssh/sshd_config

RUN set -ex; \
    mkdir -p ${PATH_CERTS} \
             ${PATH_SSH} \
             ${PATH_REPO}; \
    cd ${PATH_REPO}; \
    git init --bare

RUN if [ "x${GITHUB_USER}" = "x" ]; then exit 0; fi; \
    echo "Pulling keys of GitHub user ${GITHUB_USER}"; \
    set -ex; \
    ssh-keygen -A; \
    touch ${PATH_SSH}/authorized_keys; \
    curl -f https://github.com/${GITHUB_USER}.keys | tee -a ${PATH_SSH}/authorized_keys

#COPY ./scripts/post-receive ${REPO_PATH}/hooks/
#RUN chmod +x ${REPO_PATH}/hooks/post-receive
RUN ln -s ${PATH_KIT}/scripts/post-receive ${PATH_REPO}/hooks/post-receive

VOLUME "${PATH_CERTS}"
VOLUME "${PATH_SSH}"
VOLUME "${PATH_MOUNT}"

WORKDIR ${PATH_KIT}

COPY ./scripts ./scripts
COPY ./scripts/kubeconfig ${PATH_KIT}/

RUN set -ex; \
    git clone ${PATH_REPO} ${PATH_KIT}/wd

COPY ./kit.git/ ${PATH_KIT}/wd/

RUN set -ex; \
    git config --global user.email "kit@lazos.me"; \
    git config --global user.name "kit"; \
    cd ${PATH_KIT}/wd; \
    git add .; \
    git commit -m 'kit'; \
    git push; \
    rm -fr ${PATH_KIT}/wd

WORKDIR ${PATH_MOUNT}

RUN chown -R ${USER}:${GROUP} ${PATH_KIT}

EXPOSE 22

CMD [ "multirun", "${PATH_KIT}/scripts/cmd.sh" ]
