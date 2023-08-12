FROM alpine:3.18

ARG GIT_USER=git
ARG GIT_GROUP=git
ARG GIT_PASSWORD="12345"
ARG GIT_DATA=/var/kit
ARG GIT_CERTS=${GIT_DATA}/certs
ARG GIT_SSH=${GIT_DATA}/ssh
ARG GITHUB_USER=lajosbencz
ARG REPO_PATH=/kit.git

ENV GIT_USER=${GIT_USER}
ENV GIT_GROUP=${GIT_GROUP}
ENV GIT_PASSWORD=${GIT_PASSWORD}
#ENV GIT_DATA=${GIT_DATA}
#ENV GIT_CERTS=${GIT_CERTS}
#ENV GIT_SSH=${GIT_SSH}
ENV GITHUB_USER=${GITHUB_USER}
#ENV REPO_PATH=${REPO_PATH}

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
    addgroup "${GIT_GROUP}"; \
    adduser \
        --gecos "Git User" \
        --ingroup "${GIT_GROUP}" \
        --home "${REPO_PATH}" \
        --disabled-password \
        --shell "$(which git-shell)" \
        "${GIT_USER}" ; \
    echo "${GIT_USER}:${GIT_PASSWORD}" | chpasswd; \
    echo -e "\
PasswordAuthentication no\n\
Match user git\n\
   AuthorizedKeysFile ${GIT_SSH}/authorized_keys\n\
" \
    >> /etc/ssh/sshd_config

RUN set -ex; \
    mkdir -p ${GIT_CERTS} \
             ${GIT_SSH} \
             ${REPO_PATH}; \
    cd ${REPO_PATH}; \
    git init --bare

#COPY ./scripts/post-receive ${REPO_PATH}/hooks/
#RUN chmod +x ${REPO_PATH}/hooks/post-receive
RUN ln -s ${GIT_DATA}/scripts/post-receive ${REPO_PATH}/hooks/post-receive

VOLUME "${GIT_CERTS}"
VOLUME "${GIT_SSH}"
VOLUME "${REPO_PATH}"

WORKDIR ${GIT_DATA}

COPY ./scripts ./scripts
COPY ./scripts/kubeconfig ${GIT_DATA}/.kube/config

RUN if [ "x${GITHUB_USER}" = "x" ]; then exit 0; fi; \
    echo "Pulling keys of GitHub user ${GITHUB_USER}"; \
    set -ex; \
    ssh-keygen -A; \
    touch ${GIT_SSH}/authorized_keys; \
    curl -f https://github.com/${GITHUB_USER}.keys | tee -a ${GIT_SSH}/authorized_keys

RUN set -ex; \
    git clone ${REPO_PATH} ${GIT_DATA}/wd

COPY ./kit.git/ ${GIT_DATA}/wd/

RUN set -ex; \
    git config --global user.email "kit@lazos.me"; \
    git config --global user.name "kit"; \
    cd ${GIT_DATA}/wd; \
    git add .; \
    git commit -m 'kit'; \
    git push; \
    rm -fr ${GIT_DATA}/wd

WORKDIR ${GIT_DATA}

RUN chown -R ${GIT_USER}:${GIT_GROUP} ${GIT_DATA} ${REPO_PATH}

EXPOSE 22

CMD [ "multirun", "./scripts/cmd.sh" ]
